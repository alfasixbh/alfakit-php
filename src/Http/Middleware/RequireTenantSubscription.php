<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/**
 * `requiresTenantSubscription(fn (?Model $tenant) => …)`: asks the closure for the tenant
 * of this request and, when it says yes, runs the billing provider's own middleware.
 */
final class RequireTenantSubscription
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);
        $middleware = $panel->getTenantSubscribedMiddleware();

        if ($middleware === null || ! $panel->isTenantSubscriptionRequired()) {
            return $next($request);
        }

        return (new Pipeline(app()))
            ->send($request)
            ->through(app(Router::class)->resolveMiddleware([$middleware]))
            ->then($next);
    }
}
