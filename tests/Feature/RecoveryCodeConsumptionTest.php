<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Totp;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use Illuminate\Support\Facades\Cache;

/*
 * Security: a recovery code is consumed atomically. Two requests that loaded the same
 * user before either saved must not both spend one code.
 */

function recoveryUser(): MfaUser
{
    $user = MfaUser::query()->create(['name' => 'Mia', 'email' => 'mia'.uniqid().'@example.com', 'password' => 'password']);
    $user->saveAppAuthenticationSecret(Totp::generateSecret());

    return $user;
}

it('rejects a recovery code that another request already used, even on a stale user instance', function (): void {
    $provider = AppAuthentication::make()->recoverable()->recoveryCodeCount(3);
    $user = recoveryUser();
    $codes = $provider->regenerateRecoveryCodes($user);

    // Two concurrent requests each loaded the user before either consumed a code.
    $first = MfaUser::query()->findOrFail($user->getKey());
    $second = MfaUser::query()->findOrFail($user->getKey());

    expect($provider->verifyRecoveryCode($first, $codes[0]))->toBeTrue()
        ->and($provider->verifyRecoveryCode($second, $codes[0]))->toBeFalse()
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(2)
        // The stale instance was refreshed inside the lock.
        ->and($second->getAppAuthenticationRecoveryCodes())->toHaveCount(2)
        ->and($provider->verifyRecoveryCode($second, $codes[1]))->toBeTrue()
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(1);
});

it('does not consume a recovery code while another request holds the lock', function (): void {
    $provider = AppAuthentication::make()->recoverable()->recoveryCodeCount(3);
    $user = recoveryUser();
    $codes = $provider->regenerateRecoveryCodes($user);

    $lock = Cache::lock('alfakit-mfa-recovery|'.$user->getKey(), 10);
    expect($lock->get())->toBeTrue();

    $started = microtime(true);
    expect($provider->verifyRecoveryCode($user, $codes[0]))->toBeFalse()
        ->and(microtime(true) - $started)->toBeGreaterThan(4.5)
        ->and($user->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(3);

    $lock->release();

    expect($provider->verifyRecoveryCode($user, $codes[0]))->toBeTrue();
});
