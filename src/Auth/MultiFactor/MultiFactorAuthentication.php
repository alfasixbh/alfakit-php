<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor;

use AlfaSix\Alfakit\Auth\MultiFactor\Contracts\MultiFactorAuthenticationProvider;
use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * The sign-in side of multi-factor authentication: a user whose password was correct is
 * kept "pending" in the session (not signed in) until they pass the challenge page, and
 * trusted devices skip the challenge for a while.
 */
final class MultiFactorAuthentication
{
    /** Minutes a pending sign-in stays valid. */
    public const CHALLENGE_LIFETIME = 10;

    /** Minutes freshly generated recovery codes stay viewable and downloadable. */
    public const RECOVERY_CODES_LIFETIME = 15;

    /**
     * Called by the login page once the credentials are known. Returns the redirect to the
     * challenge page when the user has a second factor to pass, or null to sign in as usual
     * (wrong credentials, no second factor, trusted device, MFA off).
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function interceptLogin(Panel $panel, array $credentials, bool $remember): ?RedirectResponse
    {
        if (! $panel->hasMultiFactorAuthentication()) {
            return null;
        }

        $provider = self::userProvider($panel);

        if ($provider === null) {
            return null;
        }

        $user = $provider->retrieveByCredentials($credentials);

        if ($user === null || ! $provider->validateCredentials($user, $credentials)) {
            return null;
        }

        if ($user instanceof CanAccessPanel && $panel->requiresPanelAccessCheck() && ! $user->canAccessPanel($panel)) {
            return null;
        }

        if (self::getEnabledProviders($panel, $user) === [] || self::isDeviceRemembered($panel, $user)) {
            return null;
        }

        self::startChallenge($panel, $user, $remember);

        return redirect()->to($panel->getMultiFactorChallengeUrl());
    }

    public static function startChallenge(Panel $panel, Authenticatable $user, bool $remember): void
    {
        session()->put(self::sessionKey($panel), [
            'user' => $user->getAuthIdentifier(),
            'remember' => $remember,
            'expires' => now()->addMinutes(self::CHALLENGE_LIFETIME)->getTimestamp(),
        ]);
    }

    /**
     * The user whose password was accepted and who still has to pass the challenge.
     */
    public static function getChallengedUser(Panel $panel): ?Authenticatable
    {
        $pending = session()->get(self::sessionKey($panel));

        if (! is_array($pending) || ! isset($pending['user'], $pending['expires']) || (int) $pending['expires'] < time()) {
            return null;
        }

        return self::userProvider($panel)?->retrieveById($pending['user']);
    }

    public static function cancelChallenge(Panel $panel): void
    {
        session()->forget(self::sessionKey($panel));
    }

    /**
     * Sign the challenged user in and send them where they were going.
     */
    public static function completeChallenge(Panel $panel, Authenticatable $user, bool $rememberDevice = false): RedirectResponse
    {
        $pending = (array) session()->get(self::sessionKey($panel), []);

        self::cancelChallenge($panel);
        RateLimiter::clear(self::throttleKey($panel, $user));

        $guard = $panel->auth();

        if (method_exists($guard, 'login')) {
            $guard->login($user, (bool) ($pending['remember'] ?? false));
        }

        session()->regenerate();

        if ($rememberDevice) {
            self::rememberDevice($panel, $user);
        }

        return redirect()->intended($panel->getUrl());
    }

    /**
     * The panel's second factors this user has set up, keyed by id.
     *
     * @return array<string, MultiFactorAuthenticationProvider>
     */
    public static function getEnabledProviders(Panel $panel, Authenticatable $user): array
    {
        return array_filter(
            $panel->getMultiFactorAuthenticationProviders(),
            static fn (MultiFactorAuthenticationProvider $provider): bool => $provider->isEnabled($user),
        );
    }

    /**
     * Whether the user can set up at least one of the panel's second factors.
     */
    public static function supportsAnyProvider(Panel $panel, Authenticatable $user): bool
    {
        foreach ($panel->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider->isSupportedBy($user)) {
                return true;
            }
        }

        return false;
    }

    public static function throttleKey(Panel $panel, Authenticatable $user): string
    {
        return 'alfakit-mfa|'.$panel->getId().'|'.$user->getAuthIdentifier();
    }

    // ---------------------------------------------------------------- Trusted devices

    public static function rememberDevice(Panel $panel, Authenticatable $user): void
    {
        $days = $panel->getMultiFactorAuthenticationRememberDeviceDays();

        if ($days === null) {
            return;
        }

        $expires = now()->addDays($days)->getTimestamp();

        Cookie::queue(Cookie::make(
            self::deviceCookieName($panel),
            Crypt::encryptString((string) json_encode([
                'user' => $user->getAuthIdentifier(),
                'expires' => $expires,
                'signature' => self::deviceSignature($panel, $user, $expires),
            ])),
            $days * 24 * 60,
            secure: request()->isSecure() ?: null,
            httpOnly: true,
            sameSite: 'lax',
        ));
    }

    public static function isDeviceRemembered(Panel $panel, Authenticatable $user): bool
    {
        if ($panel->getMultiFactorAuthenticationRememberDeviceDays() === null) {
            return false;
        }

        $cookie = request()->cookie(self::deviceCookieName($panel));

        if (! is_string($cookie) || $cookie === '') {
            return false;
        }

        try {
            $data = json_decode(Crypt::decryptString($cookie), true);
        } catch (Throwable) {
            return false;
        }

        if (! is_array($data) || ! isset($data['user'], $data['expires'], $data['signature'])) {
            return false;
        }

        return (string) $data['user'] === (string) $user->getAuthIdentifier()
            && (int) $data['expires'] >= time()
            && hash_equals(self::deviceSignature($panel, $user, (int) $data['expires']), (string) $data['signature']);
    }

    public static function forgetDevice(Panel $panel): void
    {
        Cookie::queue(Cookie::forget(self::deviceCookieName($panel)));
    }

    public static function deviceCookieName(Panel $panel): string
    {
        return 'alfakit_mfa_device_'.Str::slug($panel->getId(), '_');
    }

    /**
     * Ties the trusted device to the user's current password and second factors, so
     * changing either signs every trusted device out of the shortcut.
     */
    private static function deviceSignature(Panel $panel, Authenticatable $user, int $expires): string
    {
        $factors = array_map(
            static fn (MultiFactorAuthenticationProvider $provider): string => $provider->getId().':'.$provider->getFingerprint($user),
            self::getEnabledProviders($panel, $user),
        );

        return hash_hmac('sha256', implode('|', [
            $panel->getId(),
            $user->getAuthIdentifier(),
            $user->getAuthPassword(),
            implode(',', $factors),
            $expires,
        ]), (string) config('app.key'));
    }

    // ---------------------------------------------------------------- Recovery codes

    /**
     * Fresh recovery codes in the `xxxxx-xxxxx` format.
     *
     * @return array<string>
     */
    public static function generateRecoveryCodes(int $count): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)),
            range(1, max(1, $count)),
        );
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', Str::lower(trim($code)), (string) config('app.key'));
    }

    /**
     * Keep new recovery codes in the session so the profile page can show them (and offer
     * them as a download) once. They are stored hashed on the user.
     *
     * @param  array<string>  $codes
     */
    public static function flashRecoveryCodes(Panel $panel, array $codes): void
    {
        session()->put('alfakit.mfa-recovery-codes.'.$panel->getId(), [
            'codes' => $codes,
            'expires' => now()->addMinutes(self::RECOVERY_CODES_LIFETIME)->getTimestamp(),
        ]);
    }

    /**
     * @return array<string>|null
     */
    public static function getFlashedRecoveryCodes(Panel $panel): ?array
    {
        $stored = session()->get('alfakit.mfa-recovery-codes.'.$panel->getId());

        if (! is_array($stored) || (int) ($stored['expires'] ?? 0) < time()) {
            return null;
        }

        return array_values(array_filter((array) ($stored['codes'] ?? []), is_string(...)));
    }

    public static function forgetFlashedRecoveryCodes(Panel $panel): void
    {
        session()->forget('alfakit.mfa-recovery-codes.'.$panel->getId());
    }

    // ---------------------------------------------------------------- Helpers

    public static function sessionKey(Panel $panel): string
    {
        return 'alfakit.mfa.'.$panel->getId();
    }

    private static function userProvider(Panel $panel): ?UserProvider
    {
        $guard = $panel->auth();

        if (! method_exists($guard, 'getProvider')) {
            return null;
        }

        return $guard->getProvider();
    }
}
