<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables;

use Illuminate\Http\Request;

/**
 * The user-controlled table state read from the query string: sort, search, filters,
 * pagination and column visibility. When a table has a query-string identifier, every
 * key is prefixed (`{id}_sort`) so several tables can coexist on one page.
 *
 * `loadDeferred` is true for Inertia partial reloads, which load the records of a table
 * that uses `deferLoading()`.
 */
final class TableState
{
    /**
     * @param  array<string, array<string, mixed>>  $filters
     * @param  array<string, string>  $columnSearches
     * @param  array<string>|null  $toggledColumns
     * @param  array<string>  $present  The state keys the query string carried, even empty
     *                                  (`search`, `sort`, `filters`, `columnSearch`, `group`,
     *                                  `groupDirection`, `perPage`, `columns`);
     *                                  session persistence restores only absent keys.
     */
    public function __construct(
        public ?string $sortColumn = null,
        public ?string $sortDirection = null,
        public ?string $search = null,
        public array $filters = [],
        public array $columnSearches = [],
        public int $page = 1,
        public int|string|null $perPage = null,
        public ?array $toggledColumns = null,
        public bool $loadDeferred = false,
        public ?string $group = null,
        public ?string $groupDirection = null,
        public array $present = [],
        public ?string $cursor = null,
        public bool $reordering = false,
    ) {}

    public static function fromRequest(Request $request, ?string $identifier = null): self
    {
        $key = static fn (string $name): string => $identifier === null ? $name : "{$identifier}_{$name}";

        // The query string is user input: `?search[]=x` arrives as an array, so scalar
        // keys ignore anything that is not a scalar and list keys keep only scalar items.
        $sort = self::scalar($request->query($key('sort')));
        $direction = strtolower(self::scalar($request->query($key('direction'))) ?? '');
        $search = trim(self::scalar($request->query($key('search'))) ?? '');
        $page = self::scalar($request->query($key('page')));
        $filters = $request->query($key('filters'));
        $columnSearches = $request->query($key('columnSearch'));
        $columns = $request->query($key('columns'));
        // `?group=` (empty) is an explicit "no grouping", overriding a default group.
        $group = $request->query->has($key('group')) ? trim(self::scalar($request->query($key('group'))) ?? '') : null;
        $groupDirection = strtolower(self::scalar($request->query($key('groupDirection'))) ?? '');

        return new self(
            sortColumn: filled($sort) ? $sort : null,
            sortDirection: in_array($direction, ['asc', 'desc'], true) ? $direction : null,
            search: $search !== '' ? $search : null,
            filters: is_array($filters) ? array_filter($filters, static fn (mixed $value, mixed $name): bool => is_string($name) && is_array($value), ARRAY_FILTER_USE_BOTH) : [],
            columnSearches: is_array($columnSearches) ? self::scalarMap($columnSearches) : [],
            page: is_numeric($page) ? max(1, (int) $page) : 1,
            perPage: self::scalar($request->query($key('perPage'))),
            toggledColumns: $request->has($key('columns')) ? self::scalarList($columns) : null,
            // Deferred tables skip their records on the first page load; the renderer then
            // asks for them with an Inertia partial reload, and every later partial reload
            // (sorting, paging, polling) keeps them loaded.
            loadDeferred: filled($request->header('X-Inertia-Partial-Component')),
            group: $group,
            groupDirection: in_array($groupDirection, ['asc', 'desc'], true) ? $groupDirection : null,
            present: array_values(array_filter(['search', 'sort', 'filters', 'columnSearch', 'group', 'groupDirection', 'perPage', 'columns'], static fn (string $name): bool => $request->query->has($key($name)))),
            // Opaque cursor of a cursor-paginated table; Laravel decodes and validates it.
            cursor: filled($cursor = self::scalar($request->query($key('cursor')))) ? $cursor : null,
            // Reorder mode of a `reorderable()` table: unpaginated unless `paginatedWhileReordering()`.
            reordering: filter_var(self::scalar($request->query($key('reordering'))), FILTER_VALIDATE_BOOL),
        );
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    private static function scalarMap(array $values): array
    {
        $result = [];

        foreach ($values as $name => $value) {
            $value = trim(self::scalar($value) ?? '');

            if (is_string($name) && $value !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string>
     */
    private static function scalarList(mixed $values): array
    {
        if (! is_array($values)) {
            $values = [$values];
        }

        // `?columns=` (an empty key) is "no toggleable column shown".
        return array_values(array_filter(array_map(strval(...), array_filter($values, is_scalar(...))), static fn (string $value): bool => $value !== ''));
    }

    public static function empty(): self
    {
        return new self;
    }
}
