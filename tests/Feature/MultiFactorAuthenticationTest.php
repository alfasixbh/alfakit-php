<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Totp;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\EmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\Notifications\VerifyEmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
 * F15: multi-factor authentication — app (TOTP) and email codes, recovery codes, trusted
 * devices, the challenge page and required set-up.
 */

beforeEach(function (): void {
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();
});

function mfaPanel(?Closure $configure = null): Panel
{
    return Panels::register('secure', function (Panel $panel) use ($configure): Panel {
        $panel->login()->profile()->pages([Dashboard::class])
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()->recoveryCodeCount(3), EmailAuthentication::make()]);

        return $configure === null ? $panel : $configure($panel);
    });
}

function mfaUser(array $attributes = []): MfaUser
{
    return MfaUser::query()->create([
        'name' => 'Mia',
        'email' => 'mia'.uniqid().'@example.com',
        'password' => 'password',
        ...$attributes,
    ]);
}

function loginToken(mixed $test): string
{
    return inertiaProps(inertiaGet($test, '/secure/login'))['page']['token'];
}

function submitLogin(mixed $test, MfaUser $user, array $extra = []): Illuminate\Testing\TestResponse
{
    return inertiaPost($test, '/secure/_alfakit/actions', ['token' => loginToken($test), 'action' => 'authenticate', 'data' => ['email' => $user->email, 'password' => 'password', ...$extra]]);
}

function challengeToken(mixed $test, string $url = '/secure/multi-factor-challenge'): string
{
    return inertiaProps(inertiaGet($test, $url))['page']['token'];
}

it('generates RFC 6238 codes', function (): void {
    // RFC 6238 test vector: secret "12345678901234567890", T = 59s → 94287082 (8 digits).
    $secret = Totp::base32Encode('12345678901234567890');

    expect(Totp::code($secret, Totp::timestep(59)))->toBe('287082')
        ->and(Totp::code($secret, Totp::timestep(1111111109)))->toBe('081804')
        ->and(Totp::base32Decode($secret))->toBe('12345678901234567890')
        ->and(Totp::verify($secret, '287082', 1, null, Totp::timestep(59)))->toBe(Totp::timestep(59))
        ->and(Totp::verify($secret, '287082', 1, Totp::timestep(59), Totp::timestep(59)))->toBeNull()
        ->and(Totp::verify($secret, 'abc', 1))->toBeNull()
        ->and(Totp::uri('JBSWY3DPEHPK3PXP', 'Acme Inc', 'mia@example.com'))->toStartWith('otpauth://totp/Acme%20Inc:mia%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=Acme%20Inc');
});

it('signs in users without a second factor directly', function (): void {
    mfaPanel();
    $user = mfaUser();

    submitLogin($this, $user)->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('asks for an app code after the password and signs in once it is correct', function (): void {
    mfaPanel();
    $secret = Totp::generateSecret();
    $user = mfaUser();
    $user->saveAppAuthenticationSecret($secret);

    submitLogin($this, $user)->assertRedirect('http://localhost/secure/multi-factor-challenge');
    $this->assertGuest();

    $props = inertiaProps(inertiaGet($this, '/secure/multi-factor-challenge'));
    $links = collect($props['page']['content'])->firstWhere('type', 'Links')['links'];

    expect($props['page']['subheading'])->toBe(__('alfakit::multi-factor.app.challenge'))
        ->and(array_column($links, 'url'))->toBe(['http://localhost/secure/login']);

    $token = $props['page']['token'];

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => '000000']])
        ->assertJsonValidationErrors('code');
    $this->assertGuest();

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => Totp::code($secret)]])
        ->assertRedirect('http://localhost/secure');

    $this->assertAuthenticatedAs($user);
});

it('does not accept the same app code twice', function (): void {
    mfaPanel();
    $secret = Totp::generateSecret();
    $user = mfaUser();
    $user->saveAppAuthenticationSecret($secret);
    $provider = new AppAuthentication;

    expect($provider->verifyChallengeCode($user, Totp::code($secret)))->toBeTrue()
        ->and($provider->verifyChallengeCode($user, Totp::code($secret)))->toBeFalse();
});

it('redirects the challenge page to the login page without a pending sign-in', function (): void {
    mfaPanel();

    $this->get('/secure/multi-factor-challenge')->assertRedirect('http://localhost/secure/login');
});

it('throttles wrong codes', function (): void {
    mfaPanel();
    $user = mfaUser();
    $user->saveAppAuthenticationSecret(Totp::generateSecret());

    submitLogin($this, $user);
    $token = challengeToken($this);

    foreach (range(1, 5) as $attempt) {
        inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => '000000']]);
    }

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => '000000']])
        ->assertJsonValidationErrors(['code' => 'Too many attempts']);
});

it('accepts each recovery code once', function (): void {
    mfaPanel();
    $user = mfaUser();
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $codes = (new AppAuthentication)->recoverable()->regenerateRecoveryCodes($user);

    expect($codes)->toHaveCount(8)
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->not->toContain($codes[0]);

    submitLogin($this, $user);
    $token = challengeToken($this, '/secure/multi-factor-challenge/recovery');

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => $codes[0]]])
        ->assertRedirect('http://localhost/secure');
    $this->assertAuthenticatedAs($user);

    $this->post('/secure/logout');
    submitLogin($this, $user->fresh());
    $token = challengeToken($this, '/secure/multi-factor-challenge/recovery');

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => $codes[0]]])
        ->assertJsonValidationErrors('code');

    expect($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(7);
});

it('emails a code on the challenge page and accepts it', function (): void {
    NotificationFacade::fake();
    mfaPanel();
    $user = mfaUser(['has_email_authentication' => true]);

    submitLogin($this, $user)->assertRedirect('http://localhost/secure/multi-factor-challenge');
    $token = challengeToken($this);

    $code = null;
    NotificationFacade::assertSentTo($user, VerifyEmailAuthentication::class, function (VerifyEmailAuthentication $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    // Reloading the page doesn't send another code while the first one is valid.
    challengeToken($this);
    NotificationFacade::assertSentToTimes($user, VerifyEmailAuthentication::class, 1);

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'verify', 'data' => ['code' => $code]])
        ->assertRedirect('http://localhost/secure');

    $this->assertAuthenticatedAs($user);
});

it('greets the user by name in the code mail, not with the default "Hello!"', function (): void {
    NotificationFacade::fake();
    mfaPanel();
    $user = mfaUser(['has_email_authentication' => true]);

    submitLogin($this, $user);
    challengeToken($this);

    NotificationFacade::assertSentTo($user, VerifyEmailAuthentication::class, function (VerifyEmailAuthentication $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe('Your sign-in code')
            ->and($mail->greeting)->toBe('Hi '.$user->name.',')
            ->and($mail->introLines)->toContain($notification->code)
            ->and($html)->toContain('Hi '.e($user->name).',')
            ->and($html)->not->toContain('Hello!');

        return true;
    });

    $anonymous = (new VerifyEmailAuthentication('123456', 10))->toMail($user);

    expect($anonymous->greeting)->toBe('Hi,');
});

it('links the other methods on the challenge page in sentence case', function (): void {
    mfaPanel();
    $user = mfaUser(['has_email_authentication' => true]);
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $user->saveAppAuthenticationRecoveryCodes(['a']);

    submitLogin($this, $user);
    $props = inertiaProps(inertiaGet($this, '/secure/multi-factor-challenge/app'));
    $links = collect($props['page']['content'])->firstWhere('type', 'Links')['links'];

    expect(array_column($links, 'label'))->toContain('Use email codes instead');

    $props = inertiaProps(inertiaGet($this, '/secure/multi-factor-challenge/recovery'));

    expect(array_column(collect($props['page']['content'])->firstWhere('type', 'Links')['links'], 'label'))->toContain('Use authenticator app instead');
});

it('remembers trusted devices', function (): void {
    mfaPanel(fn (Panel $panel): Panel => $panel->multiFactorAuthenticationRememberDevice(7));
    $secret = Totp::generateSecret();
    $user = mfaUser();
    $user->saveAppAuthenticationSecret($secret);

    submitLogin($this, $user);
    $props = inertiaProps(inertiaGet($this, '/secure/multi-factor-challenge'));

    expect(collect($props['page']['content'][0]['components'])->pluck('name'))->toContain('remember_device');

    $response = inertiaPost($this, '/secure/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'verify', 'data' => ['code' => Totp::code($secret), 'remember_device' => true]]);
    $cookie = $response->getCookie(MultiFactorAuthentication::deviceCookieName(alfakit()->getPanel('secure')), decrypt: false);

    expect($cookie)->not->toBeNull();

    $this->post('/secure/logout');
    $this->withUnencryptedCookie(MultiFactorAuthentication::deviceCookieName(alfakit()->getPanel('secure')), $cookie->getValue());

    submitLogin($this, $user)->assertRedirect('http://localhost/secure');
    $this->assertAuthenticatedAs($user);

    // A new secret ends the trust.
    $this->post('/secure/logout');
    $user->saveAppAuthenticationSecret(Totp::generateSecret());

    submitLogin($this, $user->fresh())->assertRedirect('http://localhost/secure/multi-factor-challenge');
});

it('sets up the authenticator app from the profile page and shows recovery codes once', function (): void {
    mfaPanel();
    $user = mfaUser();
    $this->actingAs($user);

    $props = inertiaProps(inertiaGet($this, '/secure/profile'));
    $token = $props['page']['token'];
    $security = collect($props['page']['content'])->firstWhere('type', 'Infolist');

    expect($security)->not->toBeNull()
        ->and(json_encode($security))->toContain('setUpAppAuthentication')->toContain('setUpEmailAuthentication');

    $modal = $this->getJson('/secure/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'setUpAppAuthentication']))->assertOk()->json();

    expect(json_encode($modal))->toContain('data:image\/svg+xml;base64');

    $secret = session('alfakit.mfa.secure-app-setup');

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'setUpAppAuthentication', 'data' => ['code' => '123456']])
        ->assertJsonValidationErrors('code');

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'setUpAppAuthentication', 'data' => ['code' => Totp::code($secret)]])
        ->assertRedirect();

    expect($user->fresh()->getAppAuthenticationSecret())->toBe($secret)
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(3);

    $profile = json_encode(inertiaProps(inertiaGet($this, '/secure/profile'))['page']['content']);
    $codes = MultiFactorAuthentication::getFlashedRecoveryCodes(alfakit()->getPanel('secure'));

    expect($codes)->toHaveCount(3)
        ->and($profile)->toContain($codes[0])->toContain('removeAppAuthentication');

    $download = $this->get('/secure/profile/recovery-codes')->assertOk();

    expect($download->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($download->getContent())->toContain($codes[2]);
});

it('requires the current password to remove a second factor', function (): void {
    mfaPanel();
    $user = mfaUser();
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $this->actingAs($user);

    $token = inertiaProps(inertiaGet($this, '/secure/profile'))['page']['token'];

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'removeAppAuthentication', 'data' => ['current_password' => 'wrong']])
        ->assertJsonValidationErrors('current_password');
    expect($user->fresh()->getAppAuthenticationSecret())->not->toBeNull();

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'removeAppAuthentication', 'data' => ['current_password' => 'password']])
        ->assertRedirect();
    expect($user->fresh()->getAppAuthenticationSecret())->toBeNull();
});

it('sets up email codes after confirming a code sent by email', function (): void {
    NotificationFacade::fake();
    mfaPanel();
    $user = mfaUser();
    $this->actingAs($user);

    $token = inertiaProps(inertiaGet($this, '/secure/profile'))['page']['token'];
    $this->getJson('/secure/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'setUpEmailAuthentication']))->assertOk();

    $code = null;
    NotificationFacade::assertSentTo($user, VerifyEmailAuthentication::class, function (VerifyEmailAuthentication $notification) use (&$code): bool {
        $code = $notification->code;

        return true;
    });

    inertiaPost($this, '/secure/_alfakit/actions', ['token' => $token, 'action' => 'setUpEmailAuthentication', 'data' => ['code' => $code]])
        ->assertRedirect();

    expect($user->fresh()->hasEmailAuthentication())->toBeTrue();

    // Signing in right away (within the minute the resend throttle holds) still emails a code.
    $this->post('/secure/logout');
    submitLogin($this, $user->fresh())->assertRedirect('http://localhost/secure/multi-factor-challenge');
    challengeToken($this);

    NotificationFacade::assertSentToTimes($user, VerifyEmailAuthentication::class, 2);
});

it('sends users without a second factor to their profile when it is required', function (): void {
    mfaPanel(fn (Panel $panel): Panel => $panel->multiFactorAuthentication([AppAuthentication::make()], isRequired: true));
    $user = mfaUser();
    $this->actingAs($user);

    $this->get('/secure')->assertRedirect('http://localhost/secure/profile');

    $props = inertiaProps(inertiaGet($this, '/secure/profile'));

    expect(json_encode($props['page']['content']))->toContain('setUpAppAuthentication');

    // Set-up actions on the profile page are allowed; other pages' endpoints are not.
    $this->getJson('/secure/_alfakit/actions/modal?'.http_build_query(['token' => $props['page']['token'], 'action' => 'setUpAppAuthentication']))->assertOk();

    $user->saveAppAuthenticationSecret(Totp::generateSecret());

    $this->get('/secure')->assertOk();
});

it('blocks internal endpoints of other pages until a required second factor is set up', function (): void {
    mfaPanel(fn (Panel $panel): Panel => $panel->multiFactorAuthentication([AppAuthentication::make()], isRequired: true));
    $user = mfaUser();
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $this->actingAs($user);

    $token = inertiaProps(inertiaGet($this, '/secure'))['page']['token'];

    $user->saveAppAuthenticationSecret(null);
    Cache::flush();

    $this->postJson('/secure/_alfakit/actions', ['token' => $token, 'action' => 'anything'])->assertForbidden();
});
