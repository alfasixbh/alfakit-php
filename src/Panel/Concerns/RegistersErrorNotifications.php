<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * Per-status error notification text, hidden and disabled statuses. Shared by the panel
 * (`HasErrorNotifications`) and pages (`Page::setUpErrorNotifications()`).
 */
trait RegistersErrorNotifications
{
    /**
     * Keyed by status code, or `default` for every status without its own entry.
     *
     * @var array<int|string, array{title?: string|Closure, body?: string|Closure|null, hidden?: true, disabled?: true}>
     */
    protected array $errorNotifications = [];

    /**
     * Set the title and body of the notification for one HTTP status, or for every status
     * without its own (`$statusCode` null).
     */
    public function registerErrorNotification(string|Closure $title, string|Closure|null $body = null, ?int $statusCode = null): static
    {
        $this->errorNotifications[self::errorNotificationKey($statusCode)] = ['title' => $title, 'body' => $body];

        return $this;
    }

    /**
     * Catch errors with this status but show no notification at all.
     */
    public function hiddenErrorNotification(?int $statusCode = null): static
    {
        $this->errorNotifications[self::errorNotificationKey($statusCode)] = ['hidden' => true];

        return $this;
    }

    /**
     * Leave errors with this status to the default handling: Inertia's error page for
     * visits, the generic "request failed" toast for internal requests.
     */
    public function disabledErrorNotification(?int $statusCode = null): static
    {
        $this->errorNotifications[self::errorNotificationKey($statusCode)] = ['disabled' => true];

        return $this;
    }

    /**
     * The registered entries with closures resolved.
     *
     * @param  Closure(mixed): mixed  $evaluate
     * @return array<int|string, array{title?: string, body?: string|null, hidden?: true, disabled?: true}>
     */
    protected function resolveRegisteredErrorNotifications(Closure $evaluate): array
    {
        $resolved = [];

        foreach ($this->errorNotifications as $status => $entry) {
            if (isset($entry['hidden']) || isset($entry['disabled'])) {
                $resolved[$status] = isset($entry['hidden']) ? ['hidden' => true] : ['disabled' => true];

                continue;
            }

            $body = $evaluate($entry['body'] ?? null);

            $resolved[$status] = [
                'title' => (string) $evaluate($entry['title'] ?? ''),
                'body' => $body === null ? null : (string) $body,
            ];
        }

        return $resolved;
    }

    private static function errorNotificationKey(?int $statusCode): string
    {
        return $statusCode === null ? 'default' : (string) $statusCode;
    }
}
