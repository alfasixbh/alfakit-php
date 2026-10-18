<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

use AlfaSix\Alfakit\AlfakitManager;
use AlfaSix\Alfakit\Notifications\BroadcastNotification;
use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

/**
 * Wires `realtime()` panels up when the application has booted: their channels, the
 * observers of their resource models and instant database notifications. Panels without
 * `realtime()` register nothing.
 */
final class Realtime
{
    private static bool $listensForDatabaseNotifications = false;

    public static function boot(AlfakitManager $manager): void
    {
        // Once per application: a new application (tests, Octane workers) has new
        // broadcasters and event dispatchers.
        self::flush();

        foreach ($manager->getPanels() as $panel) {
            self::bootPanel($panel);
        }
    }

    public static function bootPanel(Panel $panel): void
    {
        if (! $panel->isRealtimeConfigured()) {
            return;
        }

        RealtimeChannels::register($panel);

        if ($panel->isBroadcastingModelChanges()) {
            foreach ($panel->getResources() as $resource) {
                $model = ltrim($resource::getModel(), '\\');

                if (is_subclass_of($model, Model::class)) {
                    ModelChanges::observe($model);
                }
            }
        }

        self::listenForDatabaseNotifications();
    }

    /**
     * Database notifications reach the bell at once: after Laravel stores one, the user's
     * private channel is told to reload the bell (keys only, the menu refetches).
     */
    private static function listenForDatabaseNotifications(): void
    {
        if (self::$listensForDatabaseNotifications) {
            return;
        }

        self::$listensForDatabaseNotifications = true;

        Event::listen(NotificationSent::class, static function (NotificationSent $event): void {
            if ($event->channel !== 'database' || $event->notification instanceof BroadcastNotification) {
                return;
            }

            $notifiable = $event->notifiable;

            if ($notifiable instanceof Authenticatable && method_exists($notifiable, 'notify')) {
                $notifiable->notify(new BroadcastNotification([], BroadcastNotification::DATABASE_NOTIFICATIONS_SENT));
            }
        });
    }

    /** Whether stored database notifications are pushed automatically. */
    public static function pushesDatabaseNotifications(): bool
    {
        return self::$listensForDatabaseNotifications;
    }

    /** Forget what was registered (tests). */
    public static function flush(): void
    {
        self::$listensForDatabaseNotifications = false;
        RealtimeChannels::flush();
        ModelChanges::flush();
    }
}
