<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tenant billing route is only for the users `authorizeTenantBilling()` lets manage it.
 */
final class AuthorizeTenantBilling
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        abort_unless(alfakit()->getPanel($panelId)->canManageTenantBilling(), 403);

        return $next($request);
    }
}
