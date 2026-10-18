<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signed-in users of the auth kit's guard only; guests go to its sign-in page and come
 * back afterwards. Unlike a panel, no `CanAccessPanel` check: the account is the user's own.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = alfakit()->getAuthKitPanel();

        if ($panel->auth()->check()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(401);
        }

        $login = $panel->getLoginUrl();

        abort_if($login === null, 401);

        return redirect()->guest($login);
    }
}
