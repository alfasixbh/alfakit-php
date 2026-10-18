<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pwa;

use AlfaSix\Alfakit\AlfakitManager;
use AlfaSix\Alfakit\Notifications\DatabaseNotification;
use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Web Push delivery for `Panel::pwa(push: true)`: AlfaKit database notifications
 * (`Notification::sendToDatabase()`) also reach every device the user subscribed, and
 * `PushNotifications::send()` pushes anything else.
 */
final class PushNotifications
{
    private static bool $listening = false;

    public static function boot(AlfakitManager $manager): void
    {
        self::$listening = false;

        foreach ($manager->getPanels() as $panel) {
            if ($panel->hasPwaPushRoutes()) {
                self::listen();

                return;
            }
        }
    }

    public static function listen(): void
    {
        if (self::$listening) {
            return;
        }

        self::$listening = true;

        Event::listen(NotificationSent::class, static function (NotificationSent $event): void {
            if ($event->channel !== 'database' || ! $event->notification instanceof DatabaseNotification || ! $event->notifiable instanceof Authenticatable) {
                return;
            }

            $user = $event->notifiable;
            $data = $event->notification->toDatabase($user);
            $send = static fn (): int => self::send($user, self::messageFromDatabaseNotification($data));

            // Push services can be slow: requests send after the response, queues and commands at once.
            if (app()->runningInConsole()) {
                $send();
            } else {
                app()->terminating(static function () use ($send): void {
                    $send();
                });
            }
        });
    }

    /**
     * Push a message to every device the user subscribed (on panels with push on). Gone
     * subscriptions are deleted.
     *
     * ```php
     * PushNotifications::send($user, ['title' => 'Order shipped', 'body' => '#1042', 'url' => '/admin/orders/1042']);
     * ```
     *
     * @param  array{title?: string, body?: string|null, url?: string|null, icon?: string|null, tag?: string|null}  $message
     * @return int how many devices accepted it
     */
    public static function send(Authenticatable $user, array $message, ?string $panel = null): int
    {
        if (! Vapid::isConfigured() || ! PushSubscription::isAvailable()) {
            return 0;
        }

        $manager = app(AlfakitManager::class);
        $sender = app(WebPush::class);
        $sent = 0;

        $subscriptions = PushSubscription::query()->ownedBy($user)
            ->when($panel !== null, static fn ($query) => $query->where('panel', $panel))
            ->get();

        foreach ($subscriptions as $subscription) {
            $target = self::panel($manager, $subscription->panel);

            if ($target === null || ! $target->hasPwaPush()) {
                continue;
            }

            $payload = (string) json_encode(array_filter([
                'title' => $message['title'] ?? $target->getPwaName(),
                'body' => $message['body'] ?? null,
                'url' => $message['url'] ?? $target->getPwaScope(),
                'icon' => $message['icon'] ?? $target->getPwaAppleTouchIcon(),
                'tag' => $message['tag'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $result = $sender->send($subscription, $payload);

            if ($result === false) {
                $subscription->delete();
            } elseif ($result === true) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Title, plain-text body and the first action's URL of a stored notification.
     *
     * @param  array<string, mixed>  $data
     * @return array{title?: string, body?: string|null, url?: string|null}
     */
    public static function messageFromDatabaseNotification(array $data): array
    {
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
            ? trim(strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5)))
            : null;

        $url = null;

        foreach ((array) ($data['actions'] ?? []) as $action) {
            if (is_array($action) && is_string($action['url'] ?? null) && $action['url'] !== '') {
                $url = $action['url'];
                break;
            }
        }

        return array_filter([
            'title' => $text($data['title'] ?? null),
            'body' => $text($data['body'] ?? null),
            'url' => $url,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private static function panel(AlfakitManager $manager, string $id): ?Panel
    {
        try {
            return $manager->getPanel($id);
        } catch (Throwable) {
            return null;
        }
    }

    /** Forget the listener (tests). */
    public static function flush(): void
    {
        self::$listening = false;
    }
}
