<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth;

use AlfaSix\Alfakit\Auth\Notifications\RegistrationInvitation;
use AlfaSix\Alfakit\Panel;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Invitation-only registration (`Panel::invitationOnlyRegistration()`, the app auth kit's
 * `registration(invitationOnly: true)`): a signed link to the registration page for one
 * email address. Nothing is stored: the signature is checked when the page opens, then the
 * invited address is kept in the session until the registration is submitted, so a
 * visitor can only register that address.
 */
final class RegistrationInvitations
{
    public static function url(Panel $panel, string $email, DateTimeInterface|int|null $expiresAt = null): string
    {
        $expiresAt ??= now()->addDays($panel->getRegistrationInvitationDays());

        if (is_int($expiresAt)) {
            $expiresAt = now()->addSeconds($expiresAt);
        }

        return URL::temporarySignedRoute($panel->getRegistrationRouteName(), $expiresAt, ['email' => self::normalize($email)]);
    }

    /**
     * Email the invitation link and return it.
     */
    public static function send(Panel $panel, string $email, DateTimeInterface|int|null $expiresAt = null): string
    {
        $url = self::url($panel, $email, $expiresAt);

        Notification::route('mail', self::normalize($email))->notify(new RegistrationInvitation($url, $panel->getBrandName(), $panel->getRegistrationInvitationDays()));

        return $url;
    }

    /**
     * Called when the registration page opens: a valid signed link stores its address in
     * the session. Returns whether the visitor holds an invitation (now or from earlier).
     */
    public static function accept(Panel $panel, Request $request): bool
    {
        $email = $request->query('email');

        if (is_string($email) && $email !== '' && $request->hasValidSignature()) {
            $request->session()->put(self::sessionKey($panel), [
                'email' => self::normalize($email),
                'expires' => now()->addDays($panel->getRegistrationInvitationDays())->getTimestamp(),
            ]);
        }

        return self::email($panel) !== null;
    }

    /**
     * The address the visitor was invited with, or null.
     */
    public static function email(Panel $panel): ?string
    {
        if (! request()->hasSession()) {
            return null;
        }

        $stored = request()->session()->get(self::sessionKey($panel));

        if (! is_array($stored) || ! is_string($stored['email'] ?? null) || (int) ($stored['expires'] ?? 0) < time()) {
            return null;
        }

        return $stored['email'];
    }

    public static function matches(Panel $panel, mixed $email): bool
    {
        $invited = self::email($panel);

        return $invited !== null && is_string($email) && hash_equals($invited, self::normalize($email));
    }

    public static function forget(Panel $panel): void
    {
        if (request()->hasSession()) {
            request()->session()->forget(self::sessionKey($panel));
        }
    }

    public static function sessionKey(Panel $panel): string
    {
        return 'alfakit.registration-invitation.'.$panel->getId();
    }

    private static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
