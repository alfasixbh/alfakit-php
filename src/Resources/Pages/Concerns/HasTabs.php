<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\Concerns;

use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Support\Labels;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tabs above a list table, each narrowing its query:
 *
 * ```php
 * public function getTabs(): array
 * {
 *     return [
 *         'all' => Tab::make(),
 *         'published' => Tab::make()->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', true)),
 *     ];
 * }
 * ```
 *
 * The active tab is kept in the `?activeTab=` query string; an unknown or hidden key falls
 * back to the default tab, so the query string can only pick one of the tabs defined here.
 */
trait HasTabs
{
    /** Query-string key of the active list tab. */
    public const ACTIVE_TAB_QUERY_KEY = 'activeTab';

    /** Deferred Inertia prop (and defer group) with the badges of `deferBadge()` tabs. */
    public const TAB_BADGES_PROP = 'alfakitTabBadges';

    /** @var array<string, Tab>|null */
    protected ?array $cachedTabs = null;

    /**
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        return [];
    }

    /**
     * The tab shown without `?activeTab=` (the first one by default).
     */
    public function getDefaultActiveTab(): string|int|null
    {
        return array_key_first($this->getCachedTabs());
    }

    /**
     * Visible tabs, keyed by their (string) key.
     *
     * @return array<string, Tab>
     */
    public function getCachedTabs(): array
    {
        if ($this->cachedTabs !== null) {
            return $this->cachedTabs;
        }

        $tabs = [];

        foreach ($this->getTabs() as $key => $tab) {
            if ($tab->isHidden()) {
                continue;
            }

            $tabs[(string) $key] = $tab;
        }

        return $this->cachedTabs = $tabs;
    }

    public function getActiveTab(): ?string
    {
        $tabs = $this->getCachedTabs();

        if ($tabs === []) {
            return null;
        }

        $requested = request()->query(self::ACTIVE_TAB_QUERY_KEY);

        if (is_string($requested) && array_key_exists($requested, $tabs)) {
            return $requested;
        }

        $default = $this->getDefaultActiveTab();

        return $default !== null && array_key_exists((string) $default, $tabs) ? (string) $default : (string) array_key_first($tabs);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function modifyQueryWithActiveTab(Builder $query): Builder
    {
        $active = $this->getActiveTab();

        return $active === null ? $query : $this->getCachedTabs()[$active]->modifyQuery($query);
    }

    /**
     * The active tab narrows the table, except while an internal request (a record action
     * or an inline edit) resolves one record by key and the tab has
     * `excludeQueryWhenResolvingRecord()`.
     */
    protected function shouldApplyActiveTabQuery(): bool
    {
        $active = $this->getActiveTab();

        if ($active === null || ! $this->getCachedTabs()[$active]->shouldExcludeQueryWhenResolvingRecord()) {
            return true;
        }

        return ! $this->isResolvingTableRecord();
    }

    /**
     * Whether this request looks up one table record by key: the page is rebuilt for an
     * internal endpoint (not its own route) and a `record` was sent.
     */
    protected function isResolvingTableRecord(): bool
    {
        $request = request();
        $route = $request->route();

        if ($route === null || $route->getControllerClass() === static::class) {
            return false;
        }

        $record = $request->input('record');

        return is_scalar($record) && $record !== '';
    }

    public function hasDeferredTabBadges(): bool
    {
        foreach ($this->getCachedTabs() as $tab) {
            if ($tab->isBadgeDeferred()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Badges of the `deferBadge()` tabs, by tab key, for the deferred `alfakitTabBadges` prop.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getDeferredTabBadges(): array
    {
        $badges = [];

        foreach ($this->getCachedTabs() as $key => $tab) {
            if ($tab->isBadgeDeferred()) {
                $badges[$key] = array_filter(['badge' => $tab->getBadge(), 'badgeColor' => $tab->getBadgeColor()], static fn (mixed $value): bool => $value !== null);
            }
        }

        return $badges;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeTabs(): array
    {
        $tabs = $this->getCachedTabs();

        if ($tabs === []) {
            return [];
        }

        return [
            'tabs' => array_values(array_map(static function (string $key, Tab $tab): array {
                $deferred = $tab->isBadgeDeferred();

                return array_filter([
                    'key' => $key,
                    'label' => $tab->getLabel() ?? Labels::fromName($key),
                    'icon' => $tab->getIcon(),
                    'iconPosition' => $tab->getIcon() !== null ? $tab->getIconPosition()->value : null,
                    // Deferred badges arrive in the `alfakitTabBadges` prop after the page loaded.
                    'badge' => $deferred ? null : $tab->getBadge(),
                    'badgeColor' => $deferred ? null : $tab->getBadgeColor(),
                    'badgeDeferred' => $deferred ?: null,
                    'extraAttributes' => $tab->getExtraAttributes() ?: null,
                ], static fn (mixed $value): bool => $value !== null);
            }, array_keys($tabs), $tabs)),
            'activeTab' => $this->getActiveTab(),
            'tabsQueryKey' => self::ACTIVE_TAB_QUERY_KEY,
        ];
    }
}
