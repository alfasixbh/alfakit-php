<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Panel\Concerns\RegistersErrorNotifications;
use Closure;

/**
 * Page-level error notifications: turn them on or off for one page
 * (`$hasErrorNotifications`, `hasErrorNotifications()`) and change their text, hide or
 * disable statuses in `setUpErrorNotifications()`. Without either the panel's apply.
 */
trait HasErrorNotifications
{
    use RegistersErrorNotifications;

    /** Null follows the panel's `errorNotifications()`. */
    protected ?bool $hasErrorNotifications = null;

    public function hasErrorNotifications(): bool
    {
        return $this->hasErrorNotifications ?? $this->getPanel()->hasErrorNotifications();
    }

    /**
     * Register page-specific notifications with `registerErrorNotification()`,
     * `hiddenErrorNotification()` and `disabledErrorNotification()`.
     */
    protected function setUpErrorNotifications(): void {}

    /**
     * The page's `errorNotifications` prop: null when the page follows the panel, `false`
     * when the page turns them off, otherwise the panel's entries merged with the page's.
     *
     * @return array<int|string, array<string, mixed>>|false|null
     */
    protected function getErrorNotificationsSchema(): array|false|null
    {
        $this->errorNotifications = [];
        $this->setUpErrorNotifications();

        $panel = $this->getPanel();
        $enabled = $this->hasErrorNotifications();

        if ($enabled === $panel->hasErrorNotifications() && $this->errorNotifications === []) {
            return null;
        }

        if (config('app.debug')) {
            return null;
        }

        if (! $enabled) {
            return false;
        }

        return Panel::serializeErrorNotifications(array_replace(
            $panel->getErrorNotifications(),
            $this->resolveRegisteredErrorNotifications(static fn (mixed $value): mixed => $value instanceof Closure ? app()->call($value) : $value),
        ));
    }
}
