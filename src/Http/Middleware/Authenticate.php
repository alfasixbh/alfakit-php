<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);
        $guard = $panel->auth();

        if (! $guard->check()) {
            // `guestAccess()`: guest-accessible pages open without signing in.
            if ($panel->allowsGuestRequest($request)) {
                return $next($request);
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(401);
            }

            $login = $panel->getLoginUrl();

            abort_if($login === null, 403, 'This panel has no login page. Enable one with ->login() or authenticate the user yourself.');

            session()->put('url.intended', $request->fullUrl());

            return redirect()->guest($login);
        }

        $user = $guard->user();

        if ($user instanceof CanAccessPanel) {
            abort_unless($user->canAccessPanel($panel), 403);
        } elseif (! app()->isLocal() && ! app()->runningUnitTests()) {
            abort(403, 'Your User model must implement AlfaSix\\Alfakit\\Contracts\\CanAccessPanel to use Alfakit outside the local environment.');
        }

        return $next($request);
    }
}
