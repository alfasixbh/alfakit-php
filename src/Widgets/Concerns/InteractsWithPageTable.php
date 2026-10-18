<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets\Concerns;

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Gives a header or footer widget of a list page the page table as the user sees it: the
 * active tab, filters, search and sort, read from the page URL (and so kept when the
 * widget polls or loads lazily, which reload the same URL).
 *
 * ```php
 * class OrderStats extends StatsOverviewWidget
 * {
 *     use InteractsWithPageTable;
 *
 *     protected function getTablePage(): string
 *     {
 *         return ListOrders::class;
 *     }
 *
 *     protected function getStats(): array
 *     {
 *         return [Stat::make('Orders', $this->getPageTableQuery()->count())];
 *     }
 * }
 * ```
 *
 * The query is the resource's Eloquent query (tenant scope and `getEloquentQuery()`
 * included), so a widget only ever counts records the table could list.
 */
trait InteractsWithPageTable
{
    protected ?Page $tablePage = null;

    /**
     * The list page whose table the widget reads. Defaults to the page the widget is on;
     * when it is on another page, the given page is built for the current request.
     *
     * @return class-string<Page>|null
     */
    protected function getTablePage(): ?string
    {
        return null;
    }

    public function getTablePageInstance(): ?Page
    {
        if ($this->tablePage !== null) {
            return $this->tablePage;
        }

        $class = $this->getTablePage();
        $page = $this->getPage();

        if ($page !== null && ($class === null || $page instanceof $class)) {
            return $this->tablePage = $page;
        }

        if ($class === null || ! $class::canAccess()) {
            return null;
        }

        $instance = app($class);
        $instance->mount();

        return $this->tablePage = $instance;
    }

    /**
     * The page table with the request's tab, filters, search and sort applied.
     */
    public function getPageTable(): ?Table
    {
        $page = $this->getTablePageInstance();

        return $page !== null && method_exists($page, 'getTable') ? $page->getTable() : null;
    }

    /**
     * The table query with the active tab, filters and search applied, for aggregates:
     * `$this->getPageTableQuery()->sum('total')`. The table sort is left out (so aggregates
     * run on every database); each call returns a fresh query.
     *
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function getPageTableQuery(): Builder
    {
        $query = $this->getPageTable()?->getFilteredSortedQuery();

        if ($query === null) {
            throw new LogicException(sprintf('Widget [%s] uses InteractsWithPageTable but its page has no Eloquent table.', static::class));
        }

        return $query->reorder();
    }

    /**
     * The records on the table's current page.
     *
     * @return Collection<array-key, mixed>
     */
    public function getPageTableRecords(): Collection
    {
        return $this->getPageTable()?->getRecords() ?? new EloquentCollection;
    }

    /**
     * The number of records matching the table (all pages), reusing the paginator's total
     * when the table already ran it.
     */
    public function getPageTableRecordsCount(): int
    {
        $table = $this->getPageTable();
        $paginator = $table?->getPaginator();

        if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            return $paginator->total();
        }

        return $table?->getFilteredSortedQuery()?->toBase()->getCountForPagination() ?? 0;
    }
}
