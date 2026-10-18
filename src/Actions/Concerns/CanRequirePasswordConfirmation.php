<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * `->requiresPasswordConfirmation()`: the user re-enters their current password in the
 * action's modal before it runs. A successful confirmation is remembered in the session
 * (Laravel's `auth.password_confirmed_at`, shared with the `password.confirm` middleware)
 * for `auth.password_timeout` seconds, or the minutes given here, so the next sensitive
 * action within that window doesn't ask again. Wrong passwords are throttled.
 */
trait CanRequirePasswordConfirmation
{
    /** Request input (and validation error key) that carries the password. */
    public const PASSWORD_CONFIRMATION_INPUT = 'confirmPassword';

    /** Session key Laravel's `password.confirm` middleware uses too. */
    public const PASSWORD_CONFIRMED_AT_SESSION_KEY = 'auth.password_confirmed_at';

    protected bool|Closure $requiresPasswordConfirmation = false;

    protected int|Closure|null $passwordConfirmationTimeout = null;

    protected ?string $providedPassword = null;

    /**
     * @param  int|Closure|null  $rememberForMinutes  How long a confirmation is remembered
     *                                                (default: `auth.password_timeout`). 0 asks every time.
     */
    public function requiresPasswordConfirmation(bool|Closure $condition = true, int|Closure|null $rememberForMinutes = null): static
    {
        $this->requiresPasswordConfirmation = $condition;
        $this->passwordConfirmationTimeout = $rememberForMinutes;

        return $this;
    }

    public function hasPasswordConfirmation(): bool
    {
        return (bool) $this->evaluate($this->requiresPasswordConfirmation);
    }

    /**
     * Seconds a confirmation is remembered for.
     */
    public function getPasswordConfirmationTimeout(): int
    {
        $minutes = $this->evaluate($this->passwordConfirmationTimeout);

        if ($minutes !== null) {
            return max(0, (int) $minutes) * 60;
        }

        return (int) config('auth.password_timeout', 10800);
    }

    /**
     * Whether the user has to type their password now (configured and not confirmed
     * within the timeout).
     */
    public function isPasswordConfirmationRequired(): bool
    {
        if (! $this->hasPasswordConfirmation()) {
            return false;
        }

        $timeout = $this->getPasswordConfirmationTimeout();

        if ($timeout === 0 || ! request()->hasSession()) {
            return true;
        }

        $confirmedAt = (int) request()->session()->get(self::PASSWORD_CONFIRMED_AT_SESSION_KEY, 0);

        return (time() - $confirmedAt) > $timeout;
    }

    /**
     * The password the user typed in the modal (set by the action endpoint).
     */
    public function providePasswordConfirmation(?string $password): static
    {
        $this->providedPassword = $password;

        return $this;
    }

    /**
     * @throws ValidationException
     */
    protected function ensurePasswordIsConfirmed(): void
    {
        if (! $this->isPasswordConfirmationRequired()) {
            return;
        }

        $guard = alfakit()->getCurrentOrDefaultPanel()->auth();
        $user = $guard->user();
        $key = 'alfakit-password-confirmation:'.sha1(($user?->getAuthIdentifier() ?? '').'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                self::PASSWORD_CONFIRMATION_INPUT => __('alfakit::actions.password_confirmation.throttled', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $password = $this->providedPassword;

        if ($password === null || $password === '' || ! $user instanceof Authenticatable || ! $this->passwordMatches($guard, $user, $password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                self::PASSWORD_CONFIRMATION_INPUT => __('alfakit::actions.password_confirmation.incorrect'),
            ]);
        }

        RateLimiter::clear($key);

        if (request()->hasSession()) {
            request()->session()->put(self::PASSWORD_CONFIRMED_AT_SESSION_KEY, time());
        }
    }

    protected function passwordMatches(mixed $guard, Authenticatable $user, string $password): bool
    {
        if (is_object($guard) && method_exists($guard, 'getProvider')) {
            return (bool) $guard->getProvider()->validateCredentials($user, ['password' => $password]);
        }

        return Hash::check($password, $user->getAuthPassword());
    }
}
