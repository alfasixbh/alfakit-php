<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's guest pages (login, registration, password reset): a user who is already
 * signed in to the panel's guard goes to the panel, not to the application's home (where
 * Laravel's `guest` middleware would send them, e.g. a starter kit's `/dashboard`).
 */
class RedirectIfAuthenticated
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);

        if ($panel->auth()->check()) {
            return redirect()->to($panel->getUrl());
        }

        return $next($request);
    }
}
