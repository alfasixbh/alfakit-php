<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Pwa\PushSubscription;
use AlfaSix\Alfakit\Pwa\Vapid;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * `Panel::pwa(push: true)`: stores and removes the signed-in user's Web Push
 * subscription for this browser.
 */
class PushSubscriptionController
{
    public function store(Request $request): JsonResponse
    {
        [$panel, $user] = $this->context();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2048', 'url:https'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        // A P-256 point (65 bytes) and a 16-byte auth secret, base64url-encoded.
        abort_unless(strlen(Vapid::base64UrlDecode($data['keys']['p256dh'])) === 65 && strlen(Vapid::base64UrlDecode($data['keys']['auth'])) >= 16, 422);

        PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
            [
                ...PushSubscription::owner($user),
                'panel' => $panel,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
            ],
        );

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        [, $user] = $this->context();

        $endpoint = (string) $request->input('endpoint', '');

        if ($endpoint !== '') {
            PushSubscription::query()->ownedBy($user)->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();
        }

        return response()->json(['subscribed' => false]);
    }

    /**
     * @return array{0: string, 1: Authenticatable}
     */
    protected function context(): array
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $user = $panel->auth()->user();

        abort_if($user === null, 403);
        abort_unless($panel->isPwaPushConfigured() && PushSubscription::isAvailable(), 404);

        return [$panel->getId(), $user];
    }
}
