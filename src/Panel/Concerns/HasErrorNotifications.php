<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Support\Icons;
use Closure;

/**
 * Notifications shown instead of Inertia's error modal when a visit or action fails with
 * an HTTP error. Only active when `app.debug` is off, so developers still see exceptions.
 * Pages can override them (`Page::$hasErrorNotifications`, `Page::setUpErrorNotifications()`).
 */
trait HasErrorNotifications
{
    use RegistersErrorNotifications;

    protected bool|Closure $hasErrorNotifications = true;

    public function errorNotifications(bool|Closure $condition = true): static
    {
        $this->hasErrorNotifications = $condition;

        return $this;
    }

    public function hasErrorNotifications(): bool
    {
        return (bool) $this->evaluate($this->hasErrorNotifications);
    }

    /**
     * Notification text keyed by status code, plus `default`. Hidden statuses are
     * `['hidden' => true]`, disabled ones `['disabled' => true]`.
     *
     * @return array<int|string, array{title?: string, body?: string|null, hidden?: true, disabled?: true}>
     */
    public function getErrorNotifications(): array
    {
        return array_replace([
            'default' => ['title' => (string) __('alfakit::panel.errors.default.title'), 'body' => (string) __('alfakit::panel.errors.default.body')],
            '403' => ['title' => (string) __('alfakit::panel.errors.403.title'), 'body' => (string) __('alfakit::panel.errors.403.body')],
            '404' => ['title' => (string) __('alfakit::panel.errors.404.title'), 'body' => (string) __('alfakit::panel.errors.404.body')],
        ], $this->resolveRegisteredErrorNotifications(fn (mixed $value): mixed => $this->evaluate($value)));
    }

    /**
     * The `errorNotifications` schema for a set of entries: the danger icon added to every
     * shown notification.
     *
     * @param  array<int|string, array{title?: string, body?: string|null, hidden?: true, disabled?: true}>  $notifications
     * @return array<int|string, array<string, mixed>>
     */
    public static function serializeErrorNotifications(array $notifications): array
    {
        $icon = Icons::get('alfakit::notifications.danger');

        return array_map(
            static fn (array $notification): array => isset($notification['title']) ? [...$notification, 'icon' => $icon] : $notification,
            $notifications,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getErrorNotificationsSchema(): array
    {
        $enabled = $this->hasErrorNotifications() && ! config('app.debug');

        if (! $enabled) {
            return ['errorNotifications' => null];
        }

        return ['errorNotifications' => static::serializeErrorNotifications($this->getErrorNotifications())];
    }
}
