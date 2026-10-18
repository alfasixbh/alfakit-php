<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications\Concerns;

use AlfaSix\Alfakit\Notifications\BroadcastNotification;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Realtime delivery over Laravel broadcasting (needs `Panel::realtimeNotifications()` and
 * Laravel Echo in the browser).
 */
trait CanBeBroadcast
{
    /**
     * Show the notification as a toast in the user's open panel tabs right away.
     *
     * @param  Authenticatable|iterable<Authenticatable>  $users
     */
    public function broadcast(Authenticatable|iterable $users): static
    {
        foreach ($users instanceof Authenticatable ? [$users] : $users as $user) {
            if (method_exists($user, 'notify')) {
                $notification = new BroadcastNotification($this->toArray());
                $notification->id = $this->getId();

                $user->notify($notification);
            }
        }

        return $this;
    }

    /**
     * Tell the user's open tabs to reload the notifications menu.
     *
     * @param  iterable<Authenticatable>  $users
     */
    protected static function dispatchDatabaseNotificationsSent(iterable $users): void
    {
        foreach ($users as $user) {
            if (method_exists($user, 'notify')) {
                $user->notify(new BroadcastNotification([], BroadcastNotification::DATABASE_NOTIFICATIONS_SENT));
            }
        }
    }
}
