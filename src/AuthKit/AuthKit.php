<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit;

use Closure;
use Illuminate\Routing\Router;

/**
 * The app auth kit: AlfaKit's sign-in, registration, password reset, email verification,
 * two-step verification (authenticator app, emailed codes, recovery codes) and account page
 * (profile, password, two-step verification, browser sessions, account deletion) for the
 * application's own users, outside any panel, in the app's Inertia layouts.
 *
 * ```php
 * // app/Providers/AppServiceProvider.php (boot)
 * AuthKit::configure(fn (AuthKitPanel $auth) => $auth
 *     ->homeUrl('/dashboard')
 *     ->multiFactorAuthentication([AppAuthentication::make()->recoverable(), EmailAuthentication::make()]));
 *
 * // routes/web.php
 * AuthKit::routes();
 * ```
 *
 * `php artisan alfakit:auth` sets it up (provider, routes and the two Inertia pages).
 */
final class AuthKit
{
    /**
     * Configure the auth kit (from a service provider's `boot()`, so the options also hold
     * for cached routes and internal requests). Returns the auth kit panel.
     *
     * @param  (Closure(AuthKitPanel): mixed)|null  $callback
     */
    public static function configure(?Closure $callback = null): AuthKitPanel
    {
        $panel = alfakit()->getAuthKitPanel();

        if ($callback !== null) {
            $callback($panel);
        }

        return $panel;
    }

    public static function panel(): AuthKitPanel
    {
        return alfakit()->getAuthKitPanel();
    }

    public static function isEnabled(): bool
    {
        return alfakit()->hasAuthKit();
    }

    /**
     * Register the auth kit's routes (call it in `routes/web.php`). `$prefix` puts every
     * page under a path (`account` → `/account/login`); `$as` prefixes the route names
     * (`kit.` → `kit.login`), e.g. next to another authentication system. With
     * `route:cache`, set them in `configure()` with `->routeOptions()` instead, so URLs
     * are generated right on cached requests too.
     */
    public static function routes(?string $prefix = null, ?string $as = null): void
    {
        $panel = self::panel();

        if ($prefix !== null || $as !== null) {
            $panel->routeOptions($prefix, $as);
        }

        $panel->registerRoutes(app(Router::class));
    }
}
