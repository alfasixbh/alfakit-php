<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Totp;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\EmailAuthentication;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/*
 * Filament 5.3 (#19327): rate limits by user or e-mail address on the account forms, so
 * set-up codes and current passwords can't be guessed by retrying.
 */

beforeEach(function (): void {
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();
});

function limitsPanel(): Panel
{
    return Panels::register('limits', fn (Panel $panel): Panel => $panel
        ->login()
        ->registration()
        ->passwordReset()
        ->profile()
        ->pages([Dashboard::class])
        ->multiFactorAuthentication([AppAuthentication::make()->recoverable()->recoveryCodeCount(2), EmailAuthentication::make()]));
}

function limitsUser(array $attributes = []): MfaUser
{
    return MfaUser::query()->create([
        'name' => 'Lim',
        'email' => 'lim'.uniqid().'@example.com',
        'password' => 'password',
        ...$attributes,
    ]);
}

function limitsPost(mixed $test, string $url, string $action, array $data): Illuminate\Testing\TestResponse
{
    $token = inertiaProps(inertiaGet($test, $url))['page']['token'];

    return inertiaPost($test, '/limits/_alfakit/actions', ['token' => $token, 'action' => $action, 'data' => $data]);
}

function throttledMessage(Illuminate\Testing\TestResponse $response, string $field): string
{
    return (string) ($response->json('errors.'.$field.'.0') ?? '');
}

it('rate limits authenticator app set-up codes, even a correct one after five attempts', function (): void {
    limitsPanel();
    $user = limitsUser();
    $this->actingAs($user);

    $token = inertiaProps(inertiaGet($this, '/limits/profile'))['page']['token'];
    $this->getJson('/limits/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'action' => 'setUpAppAuthentication']))->assertOk();
    $secret = session('alfakit.mfa.limits-app-setup');

    foreach (range(1, 5) as $attempt) {
        inertiaPost($this, '/limits/_alfakit/actions', ['token' => $token, 'action' => 'setUpAppAuthentication', 'data' => ['code' => '000000']])
            ->assertJsonValidationErrors('code');
    }

    $response = inertiaPost($this, '/limits/_alfakit/actions', ['token' => $token, 'action' => 'setUpAppAuthentication', 'data' => ['code' => Totp::code($secret)]])
        ->assertJsonValidationErrors('code');

    expect(throttledMessage($response, 'code'))->toContain('Too many attempts')
        ->and($user->fresh()->getAppAuthenticationSecret())->toBeNull();
});

it('rate limits the current password when removing the authenticator app', function (): void {
    limitsPanel();
    $user = limitsUser();
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $this->actingAs($user);

    foreach (range(1, 5) as $attempt) {
        limitsPost($this, '/limits/profile', 'removeAppAuthentication', ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
    }

    $response = limitsPost($this, '/limits/profile', 'removeAppAuthentication', ['current_password' => 'password'])->assertJsonValidationErrors('current_password');

    expect(throttledMessage($response, 'current_password'))->toContain('Too many attempts')
        ->and($user->fresh()->getAppAuthenticationSecret())->not->toBeNull();
});

it('rate limits the current password when regenerating recovery codes', function (): void {
    limitsPanel();
    $user = limitsUser();
    $user->saveAppAuthenticationSecret(Totp::generateSecret());
    $user->saveAppAuthenticationRecoveryCodes(['a', 'b']);
    $this->actingAs($user);

    foreach (range(1, 5) as $attempt) {
        limitsPost($this, '/limits/profile', 'regenerateAppAuthenticationRecoveryCodes', ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
    }

    $response = limitsPost($this, '/limits/profile', 'regenerateAppAuthenticationRecoveryCodes', ['current_password' => 'password'])->assertJsonValidationErrors('current_password');

    expect(throttledMessage($response, 'current_password'))->toContain('Too many attempts')
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toBe(['a', 'b']);
});

it('rate limits email code set-up and removal', function (): void {
    NotificationFacade::fake();
    limitsPanel();
    $user = limitsUser();
    $this->actingAs($user);

    $token = inertiaProps(inertiaGet($this, '/limits/profile'))['page']['token'];

    foreach (range(1, 5) as $attempt) {
        inertiaPost($this, '/limits/_alfakit/actions', ['token' => $token, 'action' => 'setUpEmailAuthentication', 'data' => ['code' => '000000']])
            ->assertJsonValidationErrors('code');
    }

    $response = inertiaPost($this, '/limits/_alfakit/actions', ['token' => $token, 'action' => 'setUpEmailAuthentication', 'data' => ['code' => '000000']]);
    expect(throttledMessage($response, 'code'))->toContain('Too many attempts');

    $user->toggleEmailAuthentication(true);

    foreach (range(1, 5) as $attempt) {
        limitsPost($this, '/limits/profile', 'removeEmailAuthentication', ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
    }

    $response = limitsPost($this, '/limits/profile', 'removeEmailAuthentication', ['current_password' => 'password']);

    expect(throttledMessage($response, 'current_password'))->toContain('Too many attempts')
        ->and($user->fresh()->hasEmailAuthentication())->toBeTrue();
});

it('rate limits saving the profile', function (): void {
    limitsPanel();
    $user = limitsUser();
    $this->actingAs($user);

    foreach (range(1, 5) as $attempt) {
        limitsPost($this, '/limits/profile', 'save', ['name' => 'Lim', 'email' => 'changed@example.com', 'current_password' => 'wrong'])
            ->assertJsonValidationErrors('current_password');
    }

    // The sixth save is halted with a notification, even with the right password.
    limitsPost($this, '/limits/profile', 'save', ['name' => 'Lim', 'email' => 'changed@example.com', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->email)->not->toBe('changed@example.com')
        ->and(json_encode(session()->all()))->toContain('Too many attempts');
});

it('rate limits registration by e-mail address', function (): void {
    limitsPanel();

    foreach (range(1, 5) as $attempt) {
        limitsPost($this, '/limits/register', 'register', ['name' => 'New', 'email' => 'new@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
    }

    $response = limitsPost($this, '/limits/register', 'register', ['name' => 'New', 'email' => 'NEW@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertJsonValidationErrors('email');

    expect(throttledMessage($response, 'email'))->toContain('Too many attempts')
        ->and(MfaUser::query()->where('email', 'new@example.com')->exists())->toBeFalse();

    // Another address is not affected.
    limitsPost($this, '/limits/register', 'register', ['name' => 'Other', 'email' => 'other@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertRedirect();
});

it('rate limits resetting a password by e-mail address', function (): void {
    limitsPanel();
    Illuminate\Support\Facades\Schema::create('password_reset_tokens', function (Illuminate\Database\Schema\Blueprint $table): void {
        $table->string('email')->primary();
        $table->string('token');
        $table->timestamp('created_at')->nullable();
    });
    $user = limitsUser(['email' => 'reset@example.com']);

    foreach (range(1, 5) as $attempt) {
        limitsPost($this, '/limits/password-reset/reset?email=reset@example.com', 'reset', ['token' => 'bad', 'email' => 'reset@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
            ->assertJsonValidationErrors('email');
    }

    $token = Illuminate\Support\Facades\Password::broker()->createToken($user);

    $response = limitsPost($this, '/limits/password-reset/reset?email=reset@example.com', 'reset', ['token' => $token, 'email' => 'reset@example.com', 'password' => 'a-long-password-1', 'password_confirmation' => 'a-long-password-1'])
        ->assertJsonValidationErrors('email');

    expect(throttledMessage($response, 'email'))->toContain('Too many attempts');
});
