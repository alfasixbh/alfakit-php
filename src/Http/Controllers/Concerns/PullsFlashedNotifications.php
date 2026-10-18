<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Http\Request;
use Inertia\Support\SessionKey;

/**
 * JSON endpoints answer with the notifications sent while handling the request, taken out
 * of the flash data so the next page doesn't show them a second time.
 */
trait PullsFlashedNotifications
{
    /**
     * @return array<int, mixed>
     */
    protected function pullFlashedNotifications(Request $request): array
    {
        if (! $request->hasSession()) {
            return [];
        }

        $flash = (array) $request->session()->get(SessionKey::FLASH_DATA, []);
        $notifications = array_values((array) ($flash[Notification::FLASH_KEY] ?? []));
        unset($flash[Notification::FLASH_KEY]);

        if ($flash === []) {
            $request->session()->forget(SessionKey::FLASH_DATA);
        } else {
            $request->session()->flash(SessionKey::FLASH_DATA, $flash);
        }

        return $notifications;
    }
}
