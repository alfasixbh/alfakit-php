<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Pages\Page;

/**
 * A secondary navigation rendered next to (or above) the page content: the pages of the
 * page's cluster, or a resource's record pages (`Resource::getRecordSubNavigation()`).
 */
trait HasSubNavigation
{
    protected static ?SubNavigationPosition $subNavigationPosition = null;

    /**
     * Items (or groups) of the sub-navigation. Defaults to the cluster's pages.
     *
     * @return array<NavigationItem|NavigationGroup>
     */
    public function getSubNavigation(): array
    {
        $cluster = static::getCluster();

        return $cluster === null || ! $cluster::shouldRegisterSubNavigation() ? [] : $cluster::getClusterNavigationItems();
    }

    /**
     * The position set on this class, if any (pages, clusters and resources each have one).
     */
    public static function getConfiguredSubNavigationPosition(): ?SubNavigationPosition
    {
        return static::$subNavigationPosition;
    }

    public function getSubNavigationPosition(): SubNavigationPosition
    {
        $cluster = static::getCluster();

        return static::$subNavigationPosition
            ?? ($cluster === null ? null : $cluster::getConfiguredSubNavigationPosition())
            // The panel's `subNavigationPosition()` (default `Start`).
            ?? $this->getPanel()->getSubNavigationPosition();
    }

    /**
     * Navigation items for the given page classes, for use in `getSubNavigation()`.
     *
     * @param  array<class-string<Page>>  $pages
     * @return array<NavigationItem>
     */
    public function generateNavigationItems(array $pages): array
    {
        $items = [];

        foreach ($pages as $page) {
            array_push($items, ...$page::buildNavigationItems());
        }

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getSubNavigationSchema(): ?array
    {
        $groups = [];
        $byLabel = [];

        foreach ($this->getSubNavigation() as $entry) {
            if ($entry instanceof NavigationGroup) {
                $groups[] = $entry->collapsible(false);

                continue;
            }

            if (! $entry->isVisible()) {
                continue;
            }

            $label = $entry->getGroup() ?? '';

            if (! isset($byLabel[$label])) {
                $byLabel[$label] = NavigationGroup::make($label === '' ? null : $label)->collapsible(false);
                $label === '' ? array_unshift($groups, $byLabel[$label]) : $groups[] = $byLabel[$label];
            }

            $byLabel[$label]->items([...$byLabel[$label]->getItems(), $entry]);
        }

        $schemas = array_values(array_filter(
            array_map(static fn (NavigationGroup $group): array => $group->toSchema(), $groups),
            static fn (array $group): bool => ($group['items'] ?? []) !== [],
        ));

        if ($schemas === []) {
            return null;
        }

        return [
            'position' => $this->getSubNavigationPosition()->value,
            'groups' => $schemas,
        ];
    }

    /**
     * The page's breadcrumbs, led by the cluster's.
     *
     * @return array<int|string, string>
     */
    protected function getBreadcrumbsWithCluster(): array
    {
        $breadcrumbs = $this->getBreadcrumbsWithoutHierarchy();

        return $this->getPanel()->hasNavigationHierarchyBreadcrumbs()
            ? $this->prependNavigationHierarchy($breadcrumbs)
            : $breadcrumbs;
    }

    /**
     * `breadcrumbs(hasNavigationHierarchy: true)`: the navigation group and the parent item
     * of the active navigation item go first (when the trail doesn't start with them).
     *
     * @param  array<string|int, string>  $breadcrumbs
     * @return array<string|int, string>
     */
    protected function prependNavigationHierarchy(array $breadcrumbs): array
    {
        $hierarchy = [];

        foreach ($this->getPanel()->getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                $active = $item->isActive() ? $item : null;
                $parent = null;

                foreach ($item->getChildItems() as $child) {
                    if ($child->isActive()) {
                        [$active, $parent] = [$child, $item];
                    }
                }

                if ($active === null) {
                    continue;
                }

                if (filled($group->getLabel())) {
                    $hierarchy[] = [null, (string) $group->getLabel()];
                }

                if ($parent !== null) {
                    $hierarchy[] = [$parent->getUrl(), $parent->getLabel()];
                }

                break 2;
            }
        }

        if ($hierarchy === []) {
            return $breadcrumbs;
        }

        $result = [];

        foreach ($hierarchy as [$url, $label]) {
            // A label already in the trail (e.g. the parent page itself) isn't repeated.
            if (in_array($label, $breadcrumbs, true) || ($url !== null && array_key_exists($url, $breadcrumbs))) {
                continue;
            }

            $url === null ? $result[] = $label : $result[$url] = $label;
        }

        foreach ($breadcrumbs === [] ? [$this->getTitle()] : $breadcrumbs as $url => $label) {
            is_string($url) ? $result[$url] = $label : $result[] = $label;
        }

        return $result;
    }

    /**
     * The page's breadcrumbs, after its cluster's.
     *
     * @return array<string|int, string>
     */
    protected function getBreadcrumbsWithoutHierarchy(): array
    {
        $breadcrumbs = $this->getBreadcrumbs();
        $cluster = static::getCluster();

        if ($cluster === null) {
            return $breadcrumbs;
        }

        $list = [[$cluster::getUrl(), $cluster::getClusterBreadcrumb()]];

        foreach ($breadcrumbs === [] ? [$this->getTitle()] : $breadcrumbs as $url => $label) {
            $list[] = [is_string($url) ? $url : null, $label];
        }

        $result = [];

        foreach ($list as [$url, $label]) {
            $url === null ? $result[] = $label : $result[$url] = $label;
        }

        return $result;
    }
}
