<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Enums\PaginationMode;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use LogicException;
use ValueError;

/**
 * Pagination modes: numbered (default), simple, cursor, load more and infinite scroll.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasPaginationMode
{
    protected PaginationMode|string|Closure|null $paginationMode = null;

    /**
     * Load-more and infinite-scroll tables never load more than this many records at once.
     */
    protected int $maxAppendedRecords = 10_000;

    public function paginationMode(PaginationMode|string|Closure|null $mode): static
    {
        $this->paginationMode = $mode;
        $this->paginator = null;
        $this->records = null;

        return $this;
    }

    public function getPaginationMode(): PaginationMode
    {
        $mode = $this->evaluate($this->paginationMode);

        if (is_string($mode)) {
            try {
                $mode = PaginationMode::from($mode);
            } catch (ValueError) {
                $mode = null;
            }
        }

        if ($mode instanceof PaginationMode) {
            return $mode;
        }

        return $this->evaluate($this->isSimplePaginated) ? PaginationMode::Simple : PaginationMode::Default;
    }

    protected function getPageName(): string
    {
        return $this->queryStringIdentifier === null ? 'page' : "{$this->queryStringIdentifier}_page";
    }

    protected function getCursorName(): string
    {
        return $this->queryStringIdentifier === null ? 'cursor' : "{$this->queryStringIdentifier}_cursor";
    }

    /**
     * How many records a load-more / infinite-scroll table shows for the current page.
     */
    protected function getAppendedRecordsLimit(int $perPage): int
    {
        return max($perPage, min($this->state->page * $perPage, $this->maxAppendedRecords));
    }

    /**
     * @return LengthAwarePaginator<int, mixed>|Paginator<int, mixed>|CursorPaginator<int, mixed>
     */
    protected function paginateQuery(Builder $query, int $perPage): LengthAwarePaginator|Paginator|CursorPaginator
    {
        $mode = $this->getPaginationMode();
        $pageName = $this->getPageName();

        if ($mode === PaginationMode::Simple) {
            /** @var Paginator<int, mixed> $paginator */
            $paginator = $query->simplePaginate($perPage, ['*'], $pageName, $this->state->page);

            return $paginator;
        }

        if ($mode === PaginationMode::Cursor) {
            $orders = collect($query->getQuery()->orders ?? []);

            // A cursor stores each sorted value under its column name, so every order must
            // name a column or a select alias. Relationship and aggregate columns do (see
            // `Column::applyRelationshipSort()`); a raw `sortable(query:)` expression can't.
            $raw = $orders->first(static fn (array $order): bool => ($order['type'] ?? null) === 'Raw' || ! is_string($order['column'] ?? null));

            if ($raw !== null) {
                throw new LogicException(sprintf(
                    'Cursor pagination needs every sort to name a column or select alias; the table sorts by the raw expression [%s]. Select it under an alias and order by that alias, or use another pagination mode.',
                    is_string($raw['sql'] ?? null) ? $raw['sql'] : (string) $query->getQuery()->getGrammar()->getValue($raw['column']),
                ));
            }

            // A cursor needs a unique, deterministic order: finish with the primary key.
            $key = $query->getModel()->getQualifiedKeyName();

            if (! $orders->contains(static fn (array $order): bool => ($order['column'] ?? null) === $key || ($order['column'] ?? null) === $query->getModel()->getKeyName())) {
                $query->orderBy($key, $orders->last()['direction'] ?? 'asc');
            }

            $cursor = $this->state->cursor !== null ? Cursor::fromEncoded($this->state->cursor) : null;

            /** @var CursorPaginator<int, mixed> $paginator */
            $paginator = $query->cursorPaginate($perPage, ['*'], $this->getCursorName(), $cursor);

            return $paginator;
        }

        if ($mode->isAppending()) {
            $limit = $this->getAppendedRecordsLimit($perPage);

            return new Paginator($query->limit($limit + 1)->get(), $limit, 1, ['pageName' => $pageName]);
        }

        // `deferTotals()`: one more row instead of the count query; the total loads after.
        // A page past the end still counts now, to show the last page instead.
        if ($this->defersPaginationTotal()) {
            /** @var Paginator<int, mixed> $simple */
            $simple = (clone $query)->simplePaginate($perPage, ['*'], $pageName, $this->state->page);

            if ($simple->isNotEmpty() || $this->state->page <= 1) {
                return $simple;
            }
        }

        /** @var LengthAwarePaginator<int, mixed> $paginator */
        $paginator = $query->paginate($perPage, ['*'], $pageName, $this->state->page);

        // A page past the end (stale link, records deleted, filters narrowed) shows the
        // last page instead of an empty table.
        if ($paginator->isEmpty() && $paginator->currentPage() > $paginator->lastPage()) {
            /** @var LengthAwarePaginator<int, mixed> $paginator */
            $paginator = $query->paginate($perPage, ['*'], $pageName, $paginator->lastPage(), $paginator->total());
        }

        return $paginator;
    }

    /**
     * Paginate an in-memory collection (custom `records()`), keeping its keys.
     *
     * @param  Collection<array-key, mixed>  $records
     * @return LengthAwarePaginator<array-key, mixed>|Paginator<array-key, mixed>
     */
    protected function paginateCollection(Collection $records, int $perPage): LengthAwarePaginator|Paginator
    {
        $mode = $this->getPaginationMode();
        $page = $this->state->page;
        $options = ['pageName' => $this->getPageName()];

        if ($mode === PaginationMode::Simple) {
            return new Paginator($records->slice(($page - 1) * $perPage, $perPage + 1), $perPage, $page, $options);
        }

        if ($mode->isAppending()) {
            $limit = $this->getAppendedRecordsLimit($perPage);

            return new Paginator($records->take($limit + 1), $limit, 1, $options);
        }

        // Cursors need a query; in-memory data falls back to numbered pages.
        $lastPage = max(1, (int) ceil($records->count() / $perPage));
        $page = min($page, $lastPage);

        return new LengthAwarePaginator($records->forPage($page, $perPage), $records->count(), $perPage, $page, $options);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializePaginatorForMode(LengthAwarePaginator|Paginator|CursorPaginator $paginator, mixed $options): array
    {
        $mode = $this->getPaginationMode();
        $count = $paginator->count();

        if ($paginator instanceof CursorPaginator) {
            return [
                'mode' => PaginationMode::Cursor->value,
                'perPage' => $paginator->perPage(),
                'from' => $count > 0 ? 1 : 0,
                'to' => $count,
                'pageOptions' => $options,
                'hasMorePages' => $paginator->hasMorePages(),
                'nextCursor' => $paginator->nextCursor()?->encode(),
                'previousCursor' => $paginator->previousCursor()?->encode(),
            ];
        }

        if ($paginator instanceof LengthAwarePaginator) {
            return [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
                'pageOptions' => $options,
            ];
        }

        if ($mode->isAppending()) {
            $perPage = (int) $this->getPerPage();

            return [
                'mode' => $mode->value,
                // The page the renderer asks for next is `currentPage + 1`.
                'currentPage' => max(1, (int) ceil($count / max(1, $perPage))),
                'perPage' => $perPage,
                'from' => $count > 0 ? 1 : 0,
                'to' => $count,
                'pageOptions' => $options,
                'hasMorePages' => $paginator->hasMorePages(),
            ];
        }

        return [
            'mode' => PaginationMode::Simple->value,
            'currentPage' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'from' => $paginator->firstItem() ?? 0,
            'to' => $paginator->lastItem() ?? 0,
            'pageOptions' => $options,
            'simple' => true,
            'hasMorePages' => $paginator->hasMorePages(),
            // `deferTotals()`: numbered pagination until the total arrives.
            ...($mode === PaginationMode::Default ? ['pendingTotal' => true] : []),
        ];
    }
}
