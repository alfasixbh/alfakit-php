<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Middleware;

use AlfaSix\Alfakit\Events\ServingAlfakit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUpPanel
{
    public function handle(Request $request, Closure $next, string $panelId): Response
    {
        $panel = alfakit()->getPanel($panelId);

        alfakit()->resetRequestState();
        alfakit()->setCurrentPanel($panel);
        // Theme closures (per user, tenant or page) are resolved again for this request.
        $panel->flushThemeCache();
        $panel->boot();
        alfakit()->markAsServing();

        ServingAlfakit::dispatch($panel);

        return $next($request);
    }
}
