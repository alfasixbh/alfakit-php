<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Email verification links that lead back to the panel that sent them. Laravel's
 * `VerifyEmail` notification always links to the global `verification.verify` route (a
 * starter kit's Fortify page, or nothing at all), so a panel's registration, "resend" and
 * email change mails would leave the panel or fail. Mails sent outside a panel, or from a
 * panel without `emailVerification()`, keep Laravel's link. An application's own
 * `VerifyEmail::createUrlUsing()` (registered in its providers, which boot later) wins.
 */
final class EmailVerificationUrls
{
    public static function register(): void
    {
        if (VerifyEmail::$createUrlCallback === null) {
            VerifyEmail::createUrlUsing(static fn (mixed $notifiable): string => self::url($notifiable));
        }
    }

    public static function url(mixed $notifiable): string
    {
        $panel = alfakit()->getCurrentPanel();
        $panelRoute = $panel?->generateRouteName('auth.email-verification.verify');
        $parameters = [
            'id' => $notifiable->getKey(),
            'hash' => sha1((string) $notifiable->getEmailForVerification()),
        ];
        $expires = Carbon::now()->addMinutes((int) Config::get('auth.verification.expire', 60));

        if ($panel !== null && $panelRoute !== null && $panel->hasEmailVerification() && Route::has($panelRoute)) {
            return URL::temporarySignedRoute($panelRoute, $expires, $parameters);
        }

        return URL::temporarySignedRoute('verification.verify', $expires, $parameters);
    }
}
