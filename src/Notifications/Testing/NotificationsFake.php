<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications\Testing;

use AlfaSix\Alfakit\Notifications\Notification;
use PHPUnit\Framework\Assert;

/**
 * Records the notifications sent with `send()` after `Notification::fake()`, so tests can
 * assert on them without a page request (`Notification::assertNotified()`). Bound in the
 * container, so each test starts without one.
 */
class NotificationsFake
{
    /** @var array<int, array<string, mixed>> */
    protected array $sent = [];

    /**
     * @param  array<string, mixed>  $notification  A serialised notification (`toArray()`).
     */
    public function record(array $notification): void
    {
        $this->sent[] = $notification;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Replace a recorded notification (e.g. to add an undo button to it after it was sent).
     *
     * @param  array<string, mixed>  $notification
     */
    public function replace(int $index, array $notification): void
    {
        if (array_key_exists($index, $this->sent)) {
            $this->sent[$index] = $notification;
        }
    }

    /**
     * Without an argument: any notification was sent. With a title or a notification: one
     * with that title (and body, for a notification with a body) was sent.
     *
     * @param  array<int, array<string, mixed>>  $sent
     */
    public static function assertSentIn(array $sent, Notification|string|null $notification = null): void
    {
        if ($notification === null) {
            Assert::assertNotEmpty($sent, 'Failed asserting that a notification was sent.');

            return;
        }

        Assert::assertTrue(static::matches($sent, $notification), 'Failed asserting that the notification ['.static::describe($notification).'] was sent. Sent: '.json_encode(array_column($sent, 'title')));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sent
     */
    public static function assertNotSentIn(array $sent, Notification|string|null $notification = null): void
    {
        if ($notification === null) {
            Assert::assertEmpty($sent, 'Failed asserting that no notification was sent. Sent: '.json_encode(array_column($sent, 'title')));

            return;
        }

        Assert::assertFalse(static::matches($sent, $notification), 'Failed asserting that the notification ['.static::describe($notification).'] was not sent.');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sent
     */
    public static function matches(array $sent, Notification|string $notification): bool
    {
        $title = $notification instanceof Notification ? $notification->getTitle() : $notification;
        $body = $notification instanceof Notification ? $notification->getBody() : null;

        foreach ($sent as $candidate) {
            if (($candidate['title'] ?? null) === $title && ($body === null || ($candidate['body'] ?? null) === $body)) {
                return true;
            }
        }

        return false;
    }

    protected static function describe(Notification|string $notification): string
    {
        return $notification instanceof Notification ? (string) $notification->getTitle() : $notification;
    }
}
