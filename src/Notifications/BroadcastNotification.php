<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification as LaravelNotification;

/**
 * Pushes an Alfakit notification to the user's private channel; the renderers show it as a
 * toast (or, for `DATABASE_NOTIFICATIONS_SENT`, refresh the bell).
 */
class BroadcastNotification extends LaravelNotification
{
    use Queueable;

    /** Broadcast type of a toast. */
    public const TYPE = 'alfakit.notification';

    /** Broadcast type telling the bell menu to reload. */
    public const DATABASE_NOTIFICATIONS_SENT = 'alfakit.database-notifications.sent';

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        protected array $data,
        protected string $type = self::TYPE,
    ) {}

    /**
     * @return array<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['broadcast'];
    }

    public function toBroadcast(mixed $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->data);
    }

    public function broadcastType(): string
    {
        return $this->type;
    }
}
