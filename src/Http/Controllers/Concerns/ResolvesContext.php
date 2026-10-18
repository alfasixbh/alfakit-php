<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Support\SchemaContext;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal endpoints authorise from the encrypted context token instead of route
 * middleware (so guest pages such as the login can use them). Everything the page's own
 * route would enforce is re-checked here: the token's user and age, panel access, the
 * panel's auth middleware, the page's `canAccess()`, and the tenant.
 */
trait ResolvesContext
{
    protected function resolveContext(Request $request, string $input = 'token'): SchemaContext
    {
        $token = $request->input($input) ?? $request->query($input);

        abort_if(! is_string($token) || $token === '', 400, 'Missing Alfakit context token.');

        $context = SchemaContext::fromToken($token);

        abort_unless(alfakit()->getCurrentPanel()?->getId() === $context->panel, 403);
        abort_unless(is_a($context->page, Page::class, true), 400);

        // A token is only valid for the user it was issued to, and not forever.
        abort_unless($context->user === SchemaContext::currentUser(), 403);
        abort_if($context->isExpired(), 419, 'This page has expired. Reload it and try again.');

        return $context;
    }

    protected function resolvePage(SchemaContext $context): Page
    {
        $this->authorizePageContext($context);

        /** @var class-string<Page> $class */
        $class = $context->page;

        return $class::fromContext($context);
    }

    /**
     * Every check `resolvePage()` makes (user, panel access, auth middleware, tenant and
     * `canAccess()`) without rebuilding the page, e.g. for an undo whose record is gone.
     */
    protected function authorizePageContext(SchemaContext $context): void
    {
        /** @var class-string<Page> $class */
        $class = $context->page;
        $panel = alfakit()->getCurrentOrDefaultPanel();

        $user = $panel->auth()->user();

        // `guestAccess()`: a guest's token for a guest-accessible page. Pages that need a
        // user stay closed to guests (their tokens were issued to a user, see above).
        $asGuest = $user === null && $class::requiresAuthentication() && $panel->isGuestAccessEnabled() && $class::isGuestAccessible();

        if ($class::requiresAuthentication() && ! $asGuest) {
            abort_if($user === null, 401);

            // The app auth kit serves every user of the app (their own account).
            if ($user instanceof CanAccessPanel && $panel->requiresPanelAccessCheck()) {
                abort_unless($user->canAccessPanel($panel), 403);
            }

            $this->runPanelAuthMiddleware(request(), $panel->getAuthMiddleware());
        }

        if ($panel->hasTenancy()) {
            // The tenant comes from the page the token was issued for, never from the URL
            // of the internal endpoint, and membership is re-checked on every request.
            $key = $context->parameters['tenant'] ?? null;
            $tenant = match (true) {
                $key === null => null,
                $asGuest => $panel->findTenantForGuest($key),
                default => $panel->findTenantFor($key, $user),
            };

            abort_if($key !== null && $tenant === null, 404);

            // A tenant-prefixed endpoint URL must name the same tenant as the token, so a
            // token can't be replayed under another of the user's tenants.
            $urlTenant = request()->route()?->parameter('tenant');

            if ($urlTenant !== null) {
                $urlKey = $urlTenant instanceof \Illuminate\Database\Eloquent\Model ? $panel->getTenantRouteKey($urlTenant) : $urlTenant;

                abort_if($tenant === null || (string) $urlKey !== (string) $panel->getTenantRouteKey($tenant), 404);
            }

            alfakit()->setTenant($tenant);

            if ($tenant !== null) {
                // IdentifyTenant reads the tenant from the URL; it already ran for prefixed
                // routes and the token's tenant is set above, so it is skipped here.
                $this->runPanelAuthMiddleware(request(), array_values(array_filter(
                    $panel->getTenantMiddleware(),
                    static fn (mixed $entry): bool => ! is_string($entry) || ! str_starts_with($entry, \AlfaSix\Alfakit\Http\Middleware\IdentifyTenant::class),
                )));
            }
        }

        // A `guestAccess()` closure may still turn guests away (tenants were checked by
        // `findTenantForGuest()`).
        abort_if($asGuest && alfakit()->getTenant() === null && ! $panel->hasGuestAccess(), 401);

        abort_unless($class::canAccess(), 403);
    }

    /**
     * Run middleware the page route would have run (e.g. email verification, custom
     * guards). Any middleware that answers instead of passing the request on denies it.
     *
     * @param  array<int, mixed>  $middleware
     */
    protected function runPanelAuthMiddleware(Request $request, array $middleware): void
    {
        $middleware = array_values(array_filter(
            $middleware,
            static fn (mixed $entry): bool => ! is_string($entry) || ! str_starts_with($entry, \AlfaSix\Alfakit\Http\Middleware\Authenticate::class),
        ));

        if ($middleware === []) {
            return;
        }

        $passed = false;

        $response = (new Pipeline(app()))
            ->send($request)
            ->through(app(Router::class)->resolveMiddleware($middleware))
            ->then(static function () use (&$passed): Response {
                $passed = true;

                return response()->noContent();
            });

        abort_unless($passed, $response instanceof Response && in_array($response->getStatusCode(), [401, 403, 404], true) ? $response->getStatusCode() : 403);
    }
}
