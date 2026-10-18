<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Enums\PaginationMode;
use Closure;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * `deferTotals()`: the rows render first; the count query of numbered pagination (total
 * and last page) and the summary rows load right after, from the `table/totals` endpoint.
 * The renderer shows placeholders meanwhile and keeps the totals while only the page
 * changes (`deferredTotals.key` stays the same), so paging never counts twice.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait CanDeferTotals
{
    protected bool|Closure $isDeferringTotals = false;

    /** True while answering the totals endpoint: everything is computed in place. */
    protected bool $isResolvingDeferredTotals = false;

    /**
     * Load the record count (total, last page) and the summaries after the rows. Polled
     * and `live()` tables keep them inline, so they never go stale.
     */
    public function deferTotals(bool|Closure $condition = true): static
    {
        $this->isDeferringTotals = $condition;
        $this->paginator = null;
        $this->records = null;

        return $this;
    }

    public function hasDeferredTotals(): bool
    {
        return ! $this->isResolvingDeferredTotals
            && (bool) $this->evaluate($this->isDeferringTotals)
            && ! $this->hasCustomRecords()
            && $this->evaluate($this->pollingInterval) === null
            && $this->getTableLiveSchema() === null;
    }

    /**
     * Whether the count query of numbered pagination waits for the totals request.
     */
    protected function defersPaginationTotal(): bool
    {
        return $this->getPaginationMode() === PaginationMode::Default && $this->hasDeferredTotals();
    }

    /**
     * `{key, summaries?}` when something waits for the totals request. The key changes
     * whenever the totals can (filters, search, tab, view, per page; the page and sort
     * too when a page summary is shown).
     *
     * @return array<string, mixed>|null
     */
    protected function getDeferredTotalsSchema(): ?array
    {
        if (! $this->hasDeferredTotals() || $this->isWaitingForDeferredRecords()) {
            return null;
        }

        $summaries = $this->hasSummaries() && ((bool) $this->evaluate($this->hasPageSummary) || (bool) $this->evaluate($this->hasAllTableSummary));
        $pagination = $this->defersPaginationTotal() && $this->getPaginator() !== null && ! $this->getPaginator() instanceof LengthAwarePaginator;

        if (! $summaries && ! $pagination) {
            return null;
        }

        $query = request()->query();
        $ignored = $summaries && (bool) $this->evaluate($this->hasPageSummary) ? [] : ['page', 'cursor', 'sort', 'direction'];

        foreach ($ignored as $name) {
            unset($query[$this->queryStringIdentifier === null ? $name : "{$this->queryStringIdentifier}_{$name}"]);
        }

        ksort($query);

        $key = hash('xxh3', (string) json_encode([
            request()->path(),
            is_object($this->context) ? $this->context::class : null,
            $this->queryStringIdentifier,
            $this->getModel(),
            $query,
        ]));

        return Serializable::compact([
            'key' => $key,
            'summaries' => $summaries ?: null,
        ]);
    }

    /**
     * The totals endpoint's answer: `{total, lastPage}` of numbered pagination and the
     * summaries, for the table state of the request.
     *
     * @return array<string, mixed>
     */
    public function getDeferredTotals(): array
    {
        $this->isResolvingDeferredTotals = true;
        $this->state->loadDeferred = true;
        $this->paginator = null;
        $this->records = null;

        try {
            $paginator = $this->getPaginator();

            return Serializable::compact([
                'total' => $paginator instanceof LengthAwarePaginator ? $paginator->total() : null,
                'lastPage' => $paginator instanceof LengthAwarePaginator ? $paginator->lastPage() : null,
                'summaries' => $this->hasCustomRecords() ? null : $this->serializeSummaries(),
            ]);
        } finally {
            $this->isResolvingDeferredTotals = false;
        }
    }
}
