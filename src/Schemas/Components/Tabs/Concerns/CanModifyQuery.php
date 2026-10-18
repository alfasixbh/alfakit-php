<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Tabs\Concerns;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * A tab above a resource's list table (`ListRecords::getTabs()`) narrows the table's
 * query: `Tab::make('Published')->modifyQueryUsing(fn (Builder $query) => $query->where('is_published', true))`.
 */
trait CanModifyQuery
{
    protected ?Closure $modifyQueryUsing = null;

    protected bool|Closure $isQueryExcludedWhenResolvingRecord = false;

    protected bool|Closure $isBadgeDeferred = false;

    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function modifyQuery(Builder $query): Builder
    {
        if ($this->modifyQueryUsing === null) {
            return $query;
        }

        return $this->evaluate($this->modifyQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
    }

    /**
     * Keep the tab's query out of the lookup of a single record (a record action, an
     * inline edit), so an action that moves the record out of the tab (e.g. "Publish" on
     * the Drafts tab) still finds it when its modal submits.
     */
    public function excludeQueryWhenResolvingRecord(bool|Closure $condition = true): static
    {
        $this->isQueryExcludedWhenResolvingRecord = $condition;

        return $this;
    }

    public function shouldExcludeQueryWhenResolvingRecord(): bool
    {
        return (bool) $this->evaluate($this->isQueryExcludedWhenResolvingRecord);
    }

    /**
     * Count the badge in a deferred request after the page loaded, so a slow count (e.g.
     * over a large table) doesn't hold up the page.
     */
    public function deferBadge(bool|Closure $condition = true): static
    {
        $this->isBadgeDeferred = $condition;

        return $this;
    }

    public function isBadgeDeferred(): bool
    {
        return (bool) $this->evaluate($this->isBadgeDeferred);
    }

    /**
     * The tab's badge (e.g. a record count), evaluated for list tabs.
     */
    public function getBadge(): string|int|null
    {
        $badge = $this->evaluate($this->badge);

        return $badge === null ? null : (is_int($badge) ? $badge : (string) $badge);
    }

    /**
     * Colour closures receive the badge value as `$badge`.
     */
    public function getBadgeColor(): ?string
    {
        $color = $this->evaluate($this->badgeColor, ['badge' => $this->getBadge()]);

        return $color instanceof BackedEnum ? (string) $color->value : (is_string($color) ? $color : null);
    }
}
