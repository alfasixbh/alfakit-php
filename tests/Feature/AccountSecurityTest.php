<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\EmailChange\EmailChangeVerification;
use AlfaSix\Alfakit\Auth\Impersonation\Impersonation;
use AlfaSix\Alfakit\Auth\Notifications\NoticeOfEmailChangeRequest;
use AlfaSix\Alfakit\Auth\Notifications\VerifyEmailChange;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\UserResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
 * F15: email-change verification and impersonation.
 */

beforeEach(function (): void {
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();
});

function accountPanel(): Panel
{
    return Panels::register('account', fn (Panel $panel): Panel => $panel
        ->login()
        ->profile()
        ->emailChangeVerification()
        ->pages([Dashboard::class])
        ->resources([UserResource::class]));
}

function accountUser(array $attributes = []): MfaUser
{
    return MfaUser::query()->create([
        'name' => 'User '.uniqid(),
        'email' => 'user'.uniqid().'@example.com',
        'password' => 'password',
        ...$attributes,
    ]);
}

it('keeps a new email address pending until the link sent to it is opened', function (): void {
    NotificationFacade::fake();
    accountPanel();
    $user = accountUser(['email' => 'old@example.com']);
    $this->actingAs($user);

    $token = inertiaProps(inertiaGet($this, '/account/profile'))['page']['token'];

    inertiaPost($this, '/account/_alfakit/actions', ['token' => $token, 'action' => 'save', 'data' => [
        'name' => $user->name,
        'email' => 'new@example.com',
        'current_password' => 'password',
    ]])->assertRedirect();

    expect($user->fresh()->email)->toBe('old@example.com')
        ->and(EmailChangeVerification::getPendingEmail(alfakit()->getPanel('account'), $user))->toBe('new@example.com');

    $verifyUrl = null;
    NotificationFacade::assertSentTo(new AnonymousNotifiable, VerifyEmailChange::class, function (VerifyEmailChange $notification, array $channels, AnonymousNotifiable $notifiable) use (&$verifyUrl): bool {
        $verifyUrl = $notification->url;

        return $notifiable->routes['mail'] === 'new@example.com';
    });
    NotificationFacade::assertSentTo(new AnonymousNotifiable, NoticeOfEmailChangeRequest::class, fn (NoticeOfEmailChangeRequest $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'old@example.com' && $notification->newEmail === 'new@example.com');

    $profile = json_encode(inertiaProps(inertiaGet($this, '/account/profile'))['page']['content']);
    expect($profile)->toContain('new@example.com')->toContain('cancelEmailChange');

    // A tampered link is rejected by the signature.
    $this->get($verifyUrl.'x')->assertForbidden();

    $this->get($verifyUrl)->assertRedirect('http://localhost/account/profile');

    expect($user->fresh()->email)->toBe('new@example.com')
        ->and(EmailChangeVerification::getPendingEmail(alfakit()->getPanel('account'), $user))->toBeNull();

    // Links work once.
    $this->get($verifyUrl);
    expect($user->fresh()->email)->toBe('new@example.com');
});

it('lets the old address cancel an email change', function (): void {
    NotificationFacade::fake();
    $panel = accountPanel();
    $user = accountUser(['email' => 'keep@example.com']);
    $this->actingAs($user);
    inertiaGet($this, '/account/profile');

    EmailChangeVerification::request($panel, $user, 'attacker@example.com');

    $blockUrl = null;
    $verifyUrl = null;
    NotificationFacade::assertSentTo(new AnonymousNotifiable, NoticeOfEmailChangeRequest::class, function (NoticeOfEmailChangeRequest $notification) use (&$blockUrl): bool {
        $blockUrl = $notification->blockUrl;

        return true;
    });
    NotificationFacade::assertSentTo(new AnonymousNotifiable, VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$verifyUrl): bool {
        $verifyUrl = $notification->url;

        return true;
    });

    $this->get($blockUrl)->assertRedirect();
    $this->get($verifyUrl)->assertRedirect();

    expect($user->fresh()->email)->toBe('keep@example.com');
});

it('does not apply an email change to an address taken in the meantime', function (): void {
    $panel = accountPanel();
    $user = accountUser(['email' => 'mine@example.com']);
    $this->actingAs($user);
    inertiaGet($this, '/account/profile');

    NotificationFacade::fake();
    EmailChangeVerification::request($panel, $user, 'taken@example.com');
    accountUser(['email' => 'taken@example.com']);

    $verifyUrl = null;
    NotificationFacade::assertSentTo(new AnonymousNotifiable, VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$verifyUrl): bool {
        $verifyUrl = $notification->url;

        return true;
    });

    $this->get($verifyUrl);

    expect($user->fresh()->email)->toBe('mine@example.com');
});

it('decides who may impersonate whom', function (): void {
    $panel = accountPanel();
    $admin = accountUser(['role' => 'admin']);
    $member = accountUser(['role' => 'member']);
    $superAdmin = accountUser(['role' => 'super-admin']);
    $banned = accountUser(['role' => 'banned']);

    expect(Impersonation::canImpersonate($panel, $admin, $member))->toBeTrue()
        ->and(Impersonation::canImpersonate($panel, $admin, $admin))->toBeFalse()
        ->and(Impersonation::canImpersonate($panel, $member, $admin))->toBeFalse()
        ->and(Impersonation::canImpersonate($panel, $admin, $superAdmin))->toBeFalse()
        ->and(Impersonation::canImpersonate($panel, $admin, $banned))->toBeFalse()
        ->and(Impersonation::canImpersonate($panel, null, $member))->toBeFalse();

    // An `impersonate` ability, when defined, must allow it too.
    Gate::define('impersonate', fn (MfaUser $user, MfaUser $target): bool => $target->name !== 'Protected');
    $protected = accountUser(['role' => 'member', 'name' => 'Protected']);

    expect(Impersonation::canImpersonate($panel, $admin, $member))->toBeTrue()
        ->and(Impersonation::canImpersonate($panel, $admin, $protected))->toBeFalse();
});

it('impersonates a user from the users table and leaves again', function (): void {
    accountPanel();
    $admin = accountUser(['role' => 'admin']);
    $member = accountUser(['role' => 'member']);
    $superAdmin = accountUser(['role' => 'super-admin']);
    $this->actingAs($admin);

    // Starting and leaving are switches, not real sign-ins ("last signed in" listeners skip them).
    $switches = [];
    Illuminate\Support\Facades\Event::listen(Illuminate\Auth\Events\Login::class, function () use (&$switches): void {
        $switches[] = Impersonation::isSwitching();
    });

    $props = inertiaProps(inertiaGet($this, '/account/users'));
    $records = collect($props['page']['content'][0]['records'])->keyBy('key');

    expect(json_encode($records[$member->id]))->toContain('impersonate')
        ->and(json_encode($records[$superAdmin->id]))->not->toContain('"impersonate"');

    // Refused targets are refused on the server too.
    inertiaPost($this, '/account/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'impersonate', 'record' => $superAdmin->id])
        ->assertForbidden();

    inertiaPost($this, '/account/_alfakit/actions', ['token' => $props['page']['token'], 'component' => 'table', 'action' => 'impersonate', 'record' => $member->id])
        ->assertRedirect('http://localhost/account');

    $this->assertAuthenticatedAs($member);

    $banner = inertiaProps(inertiaGet($this, '/account'))['alfakit']['impersonation'];

    expect($banner['label'])->toContain($member->name)
        ->and($banner['leaveUrl'])->toBe('http://localhost/account/impersonation/leave');

    // No impersonating someone else while impersonating.
    expect(Impersonation::canImpersonate(alfakit()->getPanel('account'), $member, $admin))->toBeFalse();

    $this->post('/account/impersonation/leave')->assertRedirect();

    $this->assertAuthenticatedAs($admin);
    expect(inertiaProps(inertiaGet($this, '/account'))['alfakit'])->not->toHaveKey('impersonation')
        ->and($switches)->toBe([true, true])
        ->and(Impersonation::isSwitching())->toBeFalse();
});

it('does not let members impersonate', function (): void {
    accountPanel();
    $member = accountUser(['role' => 'member']);
    $other = accountUser(['role' => 'member']);
    $this->actingAs($member);

    $token = inertiaProps(inertiaGet($this, '/account/users'))['page']['token'];

    inertiaPost($this, '/account/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'impersonate', 'record' => $other->id])
        ->assertForbidden();

    $this->assertAuthenticatedAs($member);
});

it('ignores leave requests without an impersonation', function (): void {
    accountPanel();
    $member = accountUser(['role' => 'member']);
    $this->actingAs($member);

    $this->post('/account/impersonation/leave')->assertRedirect();

    $this->assertAuthenticatedAs($member);
});
