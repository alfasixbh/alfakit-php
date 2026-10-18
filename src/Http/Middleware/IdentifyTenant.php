<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves `{tenant}` from the URL and makes it the current tenant. Unknown tenants, and
 * tenants the user can't access (including users without `HasTenants`), are a 404. The
 * tenant becomes the URL default for `{tenant}`, so `route()` calls in the panel need not pass it.
 */
class IdentifyTenant
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);
        $key = $request->route('tenant');

        if ($panel->getTenantModel() === null || $key === null) {
            return $next($request);
        }

        $user = $panel->auth()->user();

        // Guests only get this far on guest-accessible pages (see `Authenticate`), which
        // show any tenant the panel's `guestAccess()` allows, without membership.
        $tenant = $user === null && $panel->allowsGuestRequest($request)
            ? $panel->findTenantForGuest($key)
            : $panel->findTenantFor($key, $user);

        abort_if($tenant === null, 404);

        alfakit()->setTenant($tenant);

        $request->route()?->setParameter('tenant', $tenant);

        // `route('alfakit.admin.resources.posts.edit', ['record' => $post])` inside the panel
        // fills `{tenant}` with the current tenant, as Filament does.
        if (is_string($key)) {
            url()->defaults(['tenant' => $key]);
        }

        return $next($request);
    }
}
