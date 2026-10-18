<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\EmailChange;

use AlfaSix\Alfakit\Auth\Notifications\NoticeOfEmailChangeRequest;
use AlfaSix\Alfakit\Auth\Notifications\VerifyEmailChange;
use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * With `Panel::emailChangeVerification()`, a new email address entered on the profile page
 * is kept pending until the link emailed to it is clicked. The old address gets a notice
 * with a link to cancel the change.
 */
final class EmailChangeVerification
{
    /** Minutes the verification link stays valid. */
    public const LIFETIME = 60;

    public static function request(Panel $panel, Model&Authenticatable $user, string $newEmail): void
    {
        $token = Str::random(40);
        $oldEmail = (string) $user->getAttribute('email');

        Cache::put(self::cacheKey($panel, $user), [
            'email' => $newEmail,
            'token' => hash('sha256', $token),
        ], now()->addMinutes(self::LIFETIME));

        $parameters = ['id' => $user->getAuthIdentifier(), 'token' => $token];
        $expires = now()->addMinutes(self::LIFETIME);

        Notification::route('mail', $newEmail)->notify(new VerifyEmailChange(
            URL::temporarySignedRoute($panel->generateRouteName('auth.email-change-verification.verify'), $expires, $parameters),
            $oldEmail,
        ));

        if ($oldEmail !== '') {
            Notification::route('mail', $oldEmail)->notify(new NoticeOfEmailChangeRequest(
                URL::temporarySignedRoute($panel->generateRouteName('auth.email-change-verification.block'), $expires, $parameters),
                $newEmail,
            ));
        }
    }

    public static function getPendingEmail(Panel $panel, Authenticatable $user): ?string
    {
        $pending = Cache::get(self::cacheKey($panel, $user));

        return is_array($pending) && is_string($pending['email'] ?? null) ? $pending['email'] : null;
    }

    /**
     * Apply the pending change when the token matches. Returns false for unknown, used,
     * cancelled or expired requests, and when the address was taken in the meantime.
     */
    public static function verify(Panel $panel, Model&Authenticatable $user, string $token): bool
    {
        $email = self::matchingPendingEmail($panel, $user, $token);

        if ($email === null) {
            return false;
        }

        Cache::forget(self::cacheKey($panel, $user));

        if ($user->newQuery()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            return false;
        }

        $user->forceFill(['email' => $email]);

        // The link was opened from the new mailbox, so the address is verified.
        if ($user instanceof MustVerifyEmail) {
            $user->forceFill(['email_verified_at' => $user->freshTimestamp()]);
        }

        $user->save();

        return true;
    }

    public static function block(Panel $panel, Authenticatable $user, string $token): bool
    {
        if (self::matchingPendingEmail($panel, $user, $token) === null) {
            return false;
        }

        Cache::forget(self::cacheKey($panel, $user));

        return true;
    }

    public static function cancel(Panel $panel, Authenticatable $user): void
    {
        Cache::forget(self::cacheKey($panel, $user));
    }

    private static function matchingPendingEmail(Panel $panel, Authenticatable $user, string $token): ?string
    {
        $pending = Cache::get(self::cacheKey($panel, $user));

        if (! is_array($pending) || ! is_string($pending['email'] ?? null) || ! hash_equals((string) ($pending['token'] ?? ''), hash('sha256', $token))) {
            return null;
        }

        return $pending['email'];
    }

    private static function cacheKey(Panel $panel, Authenticatable $user): string
    {
        return 'alfakit-email-change|'.$panel->getId().'|'.$user::class.'|'.$user->getAuthIdentifier();
    }
}
