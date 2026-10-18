<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth;

use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Auth\Rules\ThrottledRule;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Rate limits for the account forms (registration, password reset, profile and second-factor
 * set-up). Every attempt counts, whether it succeeds or not, so codes and passwords can't be
 * guessed by retrying.
 */
final class Throttle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /**
     * Record an attempt, or fail the field once the limit is reached.
     *
     * @throws ValidationException
     */
    public static function attempt(string $key, string $field, int $maxAttempts = self::MAX_ATTEMPTS, int $decaySeconds = self::DECAY_SECONDS): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([$field => self::message($key)]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * Record an attempt, or send the "too many attempts" notification and halt the action
     * (its modal or form stays open) once the limit is reached.
     *
     * @throws Halt
     */
    public static function attemptOrNotify(string $key, int $maxAttempts = self::MAX_ATTEMPTS, int $decaySeconds = self::DECAY_SECONDS): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            Notification::make()
                ->danger()
                ->title(__('alfakit::actions.rate_limited.title'))
                ->body(trans_choice('alfakit::actions.rate_limited.body', $seconds, ['seconds' => $seconds, 'minutes' => (int) ceil($seconds / 60)]))
                ->send();

            throw new Halt;
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    public static function message(string $key): string
    {
        return __('alfakit::actions.password_confirmation.throttled', ['seconds' => RateLimiter::availableIn($key)]);
    }

    /**
     * A per-user limiter key.
     */
    public static function userKey(string $name, ?Authenticatable $user = null, ?Panel $panel = null): string
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();
        $user ??= $panel->auth()->user();

        return 'alfakit-'.$name.'|'.$panel->getId().'|'.($user?->getAuthIdentifier() ?? 'ip:'.request()->ip());
    }

    /**
     * A limiter key for a guest form, keyed by the e-mail address typed in.
     */
    public static function emailKey(string $name, string $email, ?Panel $panel = null): string
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();

        return 'alfakit-'.$name.'|'.$panel->getId().'|'.sha1(mb_strtolower(trim($email)));
    }

    /**
     * A `current_password` rule that counts every attempt against `$key`.
     */
    public static function currentPasswordRule(string $key, ?Panel $panel = null): ThrottledRule
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();

        return new ThrottledRule($key, static function (mixed $value) use ($panel): bool {
            $user = $panel->auth()->user();

            return $user !== null && is_string($value) && $value !== '' && Hash::check($value, $user->getAuthPassword());
        }, static fn (): string => __('validation.current_password'));
    }
}
