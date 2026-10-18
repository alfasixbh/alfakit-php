<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Columns\Column;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Summary rows built from the columns' summarizers: one for the current page (when there
 * is more than one page), one for every record matching the filters and search, and one
 * per row group.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasSummaries
{
    protected bool|Closure $hasPageSummary = true;

    protected bool|Closure $hasAllTableSummary = true;

    /**
     * Show or hide the summary rows below the table: `pageCondition` the current page's
     * row, `allTableCondition` the row for every matching record. Group summaries stay.
     */
    public function summaries(bool|Closure|null $pageCondition = null, bool|Closure|null $allTableCondition = null): static
    {
        if ($pageCondition !== null) {
            $this->hasPageSummary = $pageCondition;
        }

        if ($allTableCondition !== null) {
            $this->hasAllTableSummary = $allTableCondition;
        }

        return $this;
    }

    /**
     * Visible columns that have at least one summarizer.
     *
     * @return array<string, Column>
     */
    public function getSummarizedColumns(): array
    {
        return array_filter($this->getVisibleColumns(), static fn (Column $column): bool => $column->hasSummary());
    }

    public function hasSummaries(): bool
    {
        return $this->getSummarizedColumns() !== [];
    }

    /**
     * The summaries of the records matched by `$query`, keyed by column name.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function summarizeQuery(Builder $query): array
    {
        $values = [];

        foreach ($this->getSummarizedColumns() as $name => $column) {
            foreach ($column->getSummarizers() as $summarizer) {
                $values[$name][] = $summarizer->toSummary($query);
            }
        }

        return $values;
    }

    /**
     * The filtered (unsorted, unpaginated) query that summaries aggregate over.
     */
    protected function getSummaryQuery(): ?Builder
    {
        return $this->getFilteredSortedQuery()?->reorder();
    }

    /**
     * @return array{page?: array{label: string, values: array<string, mixed>}, all?: array{label: string, values: array<string, mixed>}}|null
     */
    protected function serializeSummaries(): ?array
    {
        // `deferTotals()`: the summaries load with the totals request.
        if (! $this->hasSummaries() || $this->isWaitingForDeferredRecords() || $this->hasDeferredTotals()) {
            return null;
        }

        $showPage = (bool) $this->evaluate($this->hasPageSummary);
        $showAll = (bool) $this->evaluate($this->hasAllTableSummary);

        if (! $showPage && ! $showAll) {
            return null;
        }

        $query = $this->getSummaryQuery();

        if ($query === null) {
            return null;
        }

        $summaries = $showAll ? [
            'all' => [
                'label' => __('alfakit::tables.summaries.all', ['label' => $this->getPluralModelLabel()]),
                'values' => $this->summarizeQuery($query),
            ],
        ] : [];

        $records = $this->getRecords();
        $paginator = $this->getPaginator();
        $total = $paginator instanceof \Illuminate\Pagination\LengthAwarePaginator ? $paginator->total() : null;

        // A page summary only adds information when the page does not hold every record.
        if ($showPage && ! $this->isGroupsOnly() && $records->isNotEmpty() && $total !== null && $total > $records->count()) {
            $keys = $records->map(static fn (Model $record): mixed => $record->getKey())->all();

            $summaries = [
                'page' => [
                    'label' => __('alfakit::tables.summaries.page'),
                    'values' => $this->summarizeQuery((clone $query)->whereKey($keys)),
                ],
                ...$summaries,
            ];
        }

        return $summaries === [] ? null : $summaries;
    }

    protected function isWaitingForDeferredRecords(): bool
    {
        return (bool) $this->evaluate($this->isDeferLoading) && ! $this->state->loadDeferred;
    }
}
