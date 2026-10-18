<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use ReflectionClass;
use Throwable;

/**
 * The auth kit's bridge to Laravel Fortify (`AuthKitPanel::fortify()`), without a Composer
 * dependency: Fortify's classes are referenced by name and only used when installed.
 *
 * - sign-in: Fortify's `authenticateUsing()` callback and `fortify.username`;
 * - registration, password reset, password and profile updates: the app's Fortify actions
 *   bound to Fortify's contracts (`App\Actions\Fortify\*`), which validate the input;
 * - two-factor authentication: users with Fortify's two-factor authentication answer it
 *   on the auth kit's challenge page (TOTP through Fortify's provider, or a recovery code).
 */
final class Fortify
{
    public const FORTIFY = 'Laravel\\Fortify\\Fortify';

    public const CREATES_NEW_USERS = 'Laravel\\Fortify\\Contracts\\CreatesNewUsers';

    public const RESETS_USER_PASSWORDS = 'Laravel\\Fortify\\Contracts\\ResetsUserPasswords';

    public const UPDATES_USER_PASSWORDS = 'Laravel\\Fortify\\Contracts\\UpdatesUserPasswords';

    public const UPDATES_USER_PROFILE_INFORMATION = 'Laravel\\Fortify\\Contracts\\UpdatesUserProfileInformation';

    public const TWO_FACTOR_PROVIDER = 'Laravel\\Fortify\\Contracts\\TwoFactorAuthenticationProvider';

    public static function isInstalled(): bool
    {
        return class_exists(self::FORTIFY);
    }

    /**
     * The app's implementation of a Fortify action contract, or null when there is none.
     */
    public static function action(string $contract): ?object
    {
        if (! self::isInstalled() || ! interface_exists($contract) || ! app()->bound($contract)) {
            return null;
        }

        $action = app($contract);

        return is_object($action) ? $action : null;
    }

    /**
     * The attribute users sign in with (`fortify.username`, `email` by default).
     */
    public static function username(): string
    {
        return (string) config('fortify.username', 'email');
    }

    /**
     * Fortify's `authenticateUsing()` callback, when the app set one.
     */
    public static function authenticateUsing(): ?callable
    {
        if (! self::isInstalled()) {
            return null;
        }

        $callback = self::staticProperty(self::FORTIFY, 'authenticateUsingCallback');

        return is_callable($callback) ? $callback : null;
    }

    /**
     * Run the `authenticateUsing()` callback with the submitted credentials on the request.
     *
     * @param  array<string, mixed>  $input
     */
    public static function authenticate(callable $callback, array $input): ?Authenticatable
    {
        $request = request();
        $request->merge($input);

        $user = $callback($request);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Whether the user has Fortify's two-factor authentication turned on.
     */
    public static function hasTwoFactor(Authenticatable $user): bool
    {
        return self::isInstalled()
            && method_exists($user, 'hasEnabledTwoFactorAuthentication')
            && (bool) $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * Check a code from the user's authenticator app with Fortify's provider.
     */
    public static function verifyCode(Authenticatable $user, string $code): bool
    {
        $provider = self::action(self::TWO_FACTOR_PROVIDER);

        if ($provider === null || ! $user instanceof Model || $code === '' || ! method_exists($provider, 'verify')) {
            return false;
        }

        $secret = $user->getAttribute('two_factor_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        try {
            $encrypter = is_callable([self::FORTIFY, 'currentEncrypter']) ? call_user_func([self::FORTIFY, 'currentEncrypter']) : app('encrypter');
            $decrypted = is_object($encrypter) && method_exists($encrypter, 'decrypt') ? $encrypter->decrypt($secret) : null;
        } catch (Throwable) {
            return false;
        }

        if (! is_string($decrypted)) {
            return false;
        }

        return (bool) $provider->verify($decrypted, $code);
    }

    /**
     * Use up one of the user's Fortify recovery codes. Returns whether it was valid.
     */
    public static function useRecoveryCode(Authenticatable $user, string $code): bool
    {
        if ($code === '' || ! method_exists($user, 'recoveryCodes') || ! method_exists($user, 'replaceRecoveryCode')) {
            return false;
        }

        $codes = $user->recoveryCodes();
        $match = (new Collection(is_array($codes) ? $codes : []))->first(static fn (mixed $stored): bool => is_string($stored) && hash_equals($stored, $code));

        if (! is_string($match)) {
            return false;
        }

        $user->replaceRecoveryCode($match);

        $event = 'Laravel\\Fortify\\Events\\RecoveryCodeReplaced';

        if (class_exists($event)) {
            event(new $event($user, $match));
        }

        return true;
    }

    /**
     * Fire Fortify's "two-factor challenge started" event, as its own login does.
     */
    public static function challenged(Authenticatable $user): void
    {
        $event = 'Laravel\\Fortify\\Events\\TwoFactorAuthenticationChallenged';

        if (class_exists($event)) {
            event(new $event($user));
        }
    }

    private static function staticProperty(string $class, string $property): mixed
    {
        if (! class_exists($class) || ! property_exists($class, $property)) {
            return null;
        }

        return (new ReflectionClass($class))->getStaticPropertyValue($property, null);
    }
}
