<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Symfony\Component\HttpFoundation\Response;

/**
 * Echo's `authEndpoint` for `realtime()` panels: authorises the panel's private and
 * presence channels (see `RealtimeChannels`) on the panel's broadcasting connection.
 * Runs behind the panel's auth middleware, so the panel's guard decides who the user is.
 */
class BroadcastingAuthController
{
    public function __invoke(Request $request): mixed
    {
        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        $panel = alfakit()->getCurrentOrDefaultPanel();

        abort_unless($panel->hasRealtime(), 404);

        $response = Broadcast::connection($panel->getBroadcastConnection())->auth($request);

        return $response ?? response()->noContent(Response::HTTP_FORBIDDEN);
    }
}
