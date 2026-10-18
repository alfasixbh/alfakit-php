<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Support\Enums\DatabaseNotificationsPosition;
use Closure;

/**
 * Where the database notifications bell sits: the top bar (default) or the sidebar.
 * Without a top bar (`topbar(false)`) the bell moves to the sidebar on its own. The top
 * navigation layout has no sidebar, so it always keeps the bell in its bar.
 */
trait HasDatabaseNotificationsPosition
{
    protected DatabaseNotificationsPosition|string|Closure|null $databaseNotificationsPosition = null;

    /**
     * `->databaseNotificationsPosition('sidebar')`, also reachable as
     * `->databaseNotifications(position: DatabaseNotificationsPosition::Sidebar)`.
     */
    public function databaseNotificationsPosition(DatabaseNotificationsPosition|string|Closure|null $position): static
    {
        $this->databaseNotificationsPosition = $position;

        return $this;
    }

    public function getDatabaseNotificationsPosition(): DatabaseNotificationsPosition
    {
        $position = $this->evaluate($this->databaseNotificationsPosition);

        if ($position === null) {
            return $this->hasTopbar() ? DatabaseNotificationsPosition::Topbar : DatabaseNotificationsPosition::Sidebar;
        }

        return $position instanceof DatabaseNotificationsPosition ? $position : DatabaseNotificationsPosition::from((string) $position);
    }

    /**
     * Merged into the panel's `databaseNotifications` schema; `position` is only sent for the sidebar.
     *
     * @return array<string, string>
     */
    protected function getDatabaseNotificationsPositionSchema(): array
    {
        return $this->getDatabaseNotificationsPosition() === DatabaseNotificationsPosition::Sidebar ? ['position' => 'sidebar'] : [];
    }
}
