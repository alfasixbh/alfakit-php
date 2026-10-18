<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Support\Enums\GlobalSearchPosition;
use AlfaSix\Alfakit\Support\Enums\UserMenuPosition;
use Closure;

/**
 * Where navigation chrome sits: the default sub-navigation position, the global search
 * field and the user menu (top bar or sidebar), and whether breadcrumbs include the
 * navigation group and parent item.
 */
trait HasNavigationPositions
{
    protected SubNavigationPosition|Closure|null $subNavigationPosition = null;

    protected GlobalSearchPosition|string|Closure|null $globalSearchPosition = null;

    protected UserMenuPosition|string|Closure|null $userMenuPosition = null;

    protected bool|Closure $hasNavigationHierarchyBreadcrumbs = false;

    /**
     * The sub-navigation position of pages, clusters and resources that don't set
     * `$subNavigationPosition` themselves (default `Start`).
     */
    public function subNavigationPosition(SubNavigationPosition|Closure|null $position): static
    {
        $this->subNavigationPosition = $position;

        return $this;
    }

    public function getSubNavigationPosition(): SubNavigationPosition
    {
        $position = $this->evaluate($this->subNavigationPosition);

        return $position instanceof SubNavigationPosition ? $position : SubNavigationPosition::Start;
    }

    /**
     * `->globalSearchPosition(GlobalSearchPosition::Sidebar)`, also reachable as
     * `->globalSearch(position: GlobalSearchPosition::Sidebar)`. The top navigation layout
     * has no sidebar and keeps the field in its bar.
     */
    public function globalSearchPosition(GlobalSearchPosition|string|Closure|null $position): static
    {
        $this->globalSearchPosition = $position;

        return $this;
    }

    public function getGlobalSearchPosition(): GlobalSearchPosition
    {
        $position = $this->evaluate($this->globalSearchPosition);

        if ($position === null) {
            return $this->hasTopbar() ? GlobalSearchPosition::Topbar : GlobalSearchPosition::Sidebar;
        }

        return $position instanceof GlobalSearchPosition ? $position : GlobalSearchPosition::from((string) $position);
    }

    /**
     * `->userMenuPosition(UserMenuPosition::Topbar)`, also reachable as
     * `->userMenu(position: UserMenuPosition::Topbar)`. Sidebar layouts keep the menu at
     * the bottom of the sidebar by default; the top navigation layout always shows it in
     * its bar.
     */
    public function userMenuPosition(UserMenuPosition|string|Closure|null $position): static
    {
        $this->userMenuPosition = $position;

        return $this;
    }

    public function getUserMenuPosition(): UserMenuPosition
    {
        $position = $this->evaluate($this->userMenuPosition);

        if ($position === null || ! $this->hasTopbar()) {
            return UserMenuPosition::Sidebar;
        }

        return $position instanceof UserMenuPosition ? $position : UserMenuPosition::from((string) $position);
    }

    /**
     * `->breadcrumbs(hasNavigationHierarchy: true)`: breadcrumbs start with the page's
     * navigation group and parent item.
     */
    public function navigationHierarchyBreadcrumbs(bool|Closure $condition = true): static
    {
        $this->hasNavigationHierarchyBreadcrumbs = $condition;

        return $this;
    }

    public function hasNavigationHierarchyBreadcrumbs(): bool
    {
        return (bool) $this->evaluate($this->hasNavigationHierarchyBreadcrumbs);
    }
}
