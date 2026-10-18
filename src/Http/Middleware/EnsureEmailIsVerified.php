<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);
        $user = $panel->auth()->user();

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            $url = $panel->getEmailVerificationPromptUrl();

            if ($url !== null && ! $request->is(ltrim((string) parse_url($url, PHP_URL_PATH), '/'))) {
                return redirect()->to($url);
            }
        }

        return $next($request);
    }
}
