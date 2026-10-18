<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Columns\Column;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use ReflectionFunction;

/**
 * Tables over data that isn't an Eloquent query: an API response, a config file, a
 * computed report. `records()` returns rows (arrays, objects or models); the table
 * searches, sorts and paginates them on the server.
 *
 * ```php
 * $table->records(fn (): array => Http::get('https://api.example.com/plans')->json())
 * ```
 *
 * The closure may ask for `$search`, `$columnSearches`, `$sortColumn`, `$sortDirection`,
 * `$filters`, `$page` and `$recordsPerPage`. Returning a paginator means it handled
 * search, sorting and paging itself; any other result is searched, sorted and paged by
 * the table. Rows keyed by a string (or with an `id`) keep that key.
 *
 * Filters: a closure that asks for `$filters` filters the rows itself (as in Filament).
 * Otherwise the table applies them to the rows: `SelectFilter` and `TernaryFilter` compare
 * their attribute, and a filter's `query()` closure receives the rows as a `Collection`
 * (`fn (Collection $records, array $data) => $records->where(...)`), see
 * `BaseFilter::applyToRecords()`.
 *
 * Record and bulk actions and inline cell edits post a row's key back; the table resolves
 * the row again from the closure (`getRecord()`), or through `resolveRecordUsing()` when
 * looking a single row up is cheaper than loading them all.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasCustomRecords
{
    /** @var array<mixed>|Closure|null */
    protected array|Closure|null $customRecords = null;

    protected ?Closure $resolveRecordUsing = null;

    protected ?Closure $resolveSelectedRecordsUsing = null;

    /**
     * @param  array<mixed>|Closure|null  $records
     */
    public function records(array|Closure|null $records): static
    {
        $this->customRecords = $records;
        $this->paginator = null;
        $this->records = null;

        return $this;
    }

    public function hasCustomRecords(): bool
    {
        return $this->customRecords !== null;
    }

    /**
     * Resolve one row of custom `records()` by its key (`fn (string $key): ?array`), for
     * actions and inline edits. Without it the table looks the key up in the rows the
     * closure returns for the current state.
     */
    public function resolveRecordUsing(?Closure $callback): static
    {
        $this->resolveRecordUsing = $callback;

        return $this;
    }

    /**
     * Resolve the rows a bulk action runs on, across pages: `fn (array $keys): array`
     * returning rows keyed by record key. "Select all" calls it with
     * `$isTrackingDeselectedKeys = true` and the `$deselectedKeys` (always empty in
     * AlfaKit, which re-applies the filters instead): return every matching row then.
     * Without it, selected rows are looked up in what `records()` returns for the
     * current state (the current page of a paginated API).
     */
    public function resolveSelectedRecordsUsing(?Closure $callback): static
    {
        $this->resolveSelectedRecordsUsing = $callback;

        return $this;
    }

    /**
     * The selected rows of custom `records()` through `resolveSelectedRecordsUsing()`, or
     * null without it.
     *
     * @param  array<mixed>  $keys
     * @return Collection<array-key, mixed>|null
     */
    protected function resolveSelectedCustomRecords(array $keys, bool $all): ?Collection
    {
        if ($this->resolveSelectedRecordsUsing === null) {
            return null;
        }

        $keys = array_values(array_unique(array_map(strval(...), array_filter($keys, is_scalar(...)))));
        $rows = $this->evaluate($this->resolveSelectedRecordsUsing, [
            'keys' => $all ? [] : $keys,
            'isTrackingDeselectedKeys' => $all,
            'deselectedKeys' => [],
        ]);

        $rows = $this->keyCustomRecords(match (true) {
            $rows instanceof Collection => $rows,
            $rows instanceof Arrayable => collect($rows->toArray()),
            is_iterable($rows) => collect($rows),
            default => collect(),
        });

        // Only the requested keys, whatever the closure returned.
        return $all ? $rows : $rows->only($keys);
    }

    /**
     * A row of custom `records()` by key, as the renderer posted it.
     *
     * @return Model|array<mixed>|null
     */
    public function getCustomRecord(mixed $key): Model|array|null
    {
        if (! is_scalar($key)) {
            return null;
        }

        if ($this->resolveRecordUsing !== null) {
            $row = $this->evaluate($this->resolveRecordUsing, ['key' => (string) $key]);

            if ($row === null) {
                return null;
            }

            return $this->keyCustomRecords(collect([(string) $key => $row]))->first();
        }

        return $this->getCustomRecordsByKeys([$key])->first();
    }

    /**
     * Rows of custom `records()` by key, keyed like the table keys them.
     *
     * @param  array<mixed>  $keys
     * @return Collection<array-key, mixed>
     */
    public function getCustomRecordsByKeys(array $keys): Collection
    {
        $keys = array_map(strval(...), array_filter($keys, is_scalar(...)));

        if ($keys === []) {
            return collect();
        }

        if ($this->resolveRecordUsing !== null) {
            $rows = [];

            foreach (array_unique($keys) as $key) {
                $row = $this->evaluate($this->resolveRecordUsing, ['key' => $key]);

                if ($row !== null) {
                    $rows[$key] = $row;
                }
            }

            return $this->keyCustomRecords(collect($rows));
        }

        return $this->getCustomRecordsCollection()->only($keys);
    }

    protected function evaluateCustomRecords(): mixed
    {
        $sort = $this->getEffectiveSort();
        $perPage = $this->getPerPage();

        return $this->evaluate($this->customRecords, [
            'search' => $this->state->search,
            'columnSearches' => $this->state->columnSearches,
            'sortColumn' => $sort['column'] ?? null,
            'sortDirection' => $sort['direction'] ?? null,
            'page' => $this->state->page,
            'recordsPerPage' => $perPage,
            'filters' => array_map(static fn ($filter): array => $filter->getState(), $this->getFilters()),
        ]);
    }

    /**
     * @return LengthAwarePaginator<array-key, mixed>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed>|null
     */
    protected function getCustomRecordsPaginator(int $perPage): LengthAwarePaginator|Paginator|CursorPaginator|null
    {
        $result = $this->evaluateCustomRecords();

        if ($result instanceof AbstractPaginator || $result instanceof AbstractCursorPaginator) {
            $result->setCollection($this->keyCustomRecords(collect($result->items())));

            /** @var LengthAwarePaginator<array-key, mixed>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed> $result */
            return $result;
        }

        return $this->paginateCollection($this->prepareCustomRecords($result), $perPage);
    }

    /**
     * Every row after search and sorting (unpaginated tables).
     *
     * @return Collection<array-key, mixed>
     */
    protected function getCustomRecordsCollection(): Collection
    {
        $result = $this->evaluateCustomRecords();

        if ($result instanceof AbstractPaginator || $result instanceof AbstractCursorPaginator) {
            return $this->keyCustomRecords(collect($result->items()));
        }

        return $this->prepareCustomRecords($result);
    }

    /**
     * @return Collection<array-key, mixed>
     */
    protected function prepareCustomRecords(mixed $result): Collection
    {
        $rows = $this->keyCustomRecords(match (true) {
            $result instanceof Collection => $result,
            $result instanceof Arrayable => collect($result->toArray()),
            is_iterable($result) => collect($result),
            default => collect(),
        });

        return $this->sortCustomRecords($this->searchCustomRecords($this->filterCustomRecords($rows)));
    }

    /**
     * Apply the active filters to the rows, unless the `records()` closure asks for
     * `$filters` and so filters them itself.
     *
     * @param  Collection<array-key, mixed>  $rows
     * @return Collection<array-key, mixed>
     */
    protected function filterCustomRecords(Collection $rows): Collection
    {
        if ($this->customRecordsFilterThemselves()) {
            return $rows;
        }

        foreach ($this->getFilters() as $filter) {
            $rows = $filter->applyToRecords($rows);
        }

        return $rows;
    }

    protected function customRecordsFilterThemselves(): bool
    {
        if (! $this->customRecords instanceof Closure) {
            return false;
        }

        foreach ((new ReflectionFunction($this->customRecords))->getParameters() as $parameter) {
            if ($parameter->getName() === 'filters') {
                return true;
            }
        }

        return false;
    }

    /**
     * Key rows by their record key: string keys are kept, list rows use their `id` (or
     * key name for models), falling back to their position.
     *
     * @param  Collection<array-key, mixed>  $rows
     * @return Collection<array-key, mixed>
     */
    protected function keyCustomRecords(Collection $rows): Collection
    {
        $isList = array_is_list($rows->all());
        $keyed = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row) && ! $row instanceof Model) {
                $row = $row instanceof Arrayable ? $row->toArray() : (array) $row;
            }

            $key = match (true) {
                $row instanceof Model => $row->getKey(),
                ! $isList => $index,
                default => data_get($row, 'id') ?? $index,
            };

            $keyed[is_scalar($key) ? (string) $key : (string) $index] = $row;
        }

        return collect($keyed);
    }

    /**
     * @param  Collection<array-key, mixed>  $rows
     * @return Collection<array-key, mixed>
     */
    protected function searchCustomRecords(Collection $rows): Collection
    {
        $visible = array_filter($this->getColumns(), static fn (Column $column): bool => ! $column->isHidden());

        if ($this->state->search !== null) {
            $columns = array_filter($visible, static fn (Column $column): bool => $column->isGloballySearchable());
            $terms = array_filter(preg_split('/\s+/', $this->state->search) ?: [], static fn (string $term): bool => $term !== '');

            if ($columns !== []) {
                $rows = $rows->filter(function (mixed $row) use ($columns, $terms): bool {
                    foreach ($terms as $term) {
                        $matches = false;

                        foreach ($columns as $column) {
                            if ($this->customRecordContains($column, $row, $term)) {
                                $matches = true;

                                break;
                            }
                        }

                        if (! $matches) {
                            return false;
                        }
                    }

                    return true;
                });
            }
        }

        foreach ($this->state->columnSearches as $name => $term) {
            $column = $visible[$name] ?? null;

            if ($column !== null && $column->isIndividuallySearchable()) {
                $rows = $rows->filter(fn (mixed $row): bool => $this->customRecordContains($column, $row, $term));
            }
        }

        return $rows;
    }

    protected function customRecordContains(Column $column, mixed $row, string $term): bool
    {
        $value = $this->customRecordValue($column, $row);
        $values = is_array($value) ? array_filter(array_map(static fn (mixed $item): ?string => is_scalar($item) ? (string) $item : null, $value)) : [is_scalar($value) ? (string) $value : ''];

        foreach ($values as $text) {
            if (mb_stripos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Model|array<mixed>  $row
     */
    protected function customRecordValue(Column $column, Model|array $row): mixed
    {
        $column->record($row);
        $value = Serializable::value($column->getState());
        $column->record(null);

        return $value;
    }

    /**
     * @param  Collection<array-key, mixed>  $rows
     * @return Collection<array-key, mixed>
     */
    protected function sortCustomRecords(Collection $rows): Collection
    {
        $sort = $this->getEffectiveSort();
        $group = $this->getGrouping();

        if ($sort === null && $group === null) {
            return $rows;
        }

        // Grouped rows stay together: the group key orders first, the table's sort within it.
        $groupDirection = $this->getGroupingDirection() === 'desc' ? -1 : 1;
        $groupKey = static fn (mixed $row): ?string => $group !== null && (is_array($row) || $row instanceof Model) ? $group->getKey($row) : null;
        $column = $sort === null ? null : $this->getColumn($sort['column']);
        $direction = ($sort['direction'] ?? 'asc') === 'desc' ? -1 : 1;
        $value = fn (mixed $row): mixed => match (true) {
            $sort === null => null,
            $column !== null => $this->customRecordValue($column, $row),
            default => data_get($row, $sort['column']),
        };

        return $rows->sort(static function (mixed $a, mixed $b) use ($value, $direction, $group, $groupKey, $groupDirection): int {
            if ($group !== null) {
                $leftGroup = $groupKey($a);
                $rightGroup = $groupKey($b);

                $byGroup = match (true) {
                    $leftGroup === $rightGroup => 0,
                    $leftGroup === null => -1,
                    $rightGroup === null => 1,
                    default => strnatcasecmp($leftGroup, $rightGroup),
                };

                if ($byGroup !== 0) {
                    return $byGroup * $groupDirection;
                }
            }

            $left = $value($a);
            $right = $value($b);

            if (is_array($left)) {
                $left = implode(', ', array_filter($left, is_scalar(...)));
            }

            if (is_array($right)) {
                $right = implode(', ', array_filter($right, is_scalar(...)));
            }

            $result = match (true) {
                $left === null && $right === null => 0,
                $left === null => -1,
                $right === null => 1,
                is_numeric($left) && is_numeric($right) => $left <=> $right,
                default => strnatcasecmp(is_scalar($left) ? (string) $left : '', is_scalar($right) ? (string) $right : ''),
            };

            return $result * $direction;
        });
    }
}
