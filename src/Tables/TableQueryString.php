<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables;

use Illuminate\Http\Request;

/**
 * Internal endpoints don't share the page's URL, so the renderer sends the page's query
 * string as `tableQuery` and the table state (sort, search, filters, column searches,
 * toggled columns, grouping, saved view) is read from it, as on the page itself. Only table-state keys are
 * taken, optionally prefixed with a table's query-string identifier, so the page URL can
 * never override other request input.
 */
final class TableQueryString
{
    private const KEY_PATTERN = '/^(?:[A-Za-z0-9_-]+_)?(?:sort|direction|search|filters|columnSearch|columns|perPage|group|groupDirection|activeTab|reordering|view)$/';

    /** The same keys plus the page and cursor (the totals endpoint answers for one page). */
    private const PAGINATED_KEY_PATTERN = '/^(?:[A-Za-z0-9_-]+_)?(?:sort|direction|search|filters|columnSearch|columns|perPage|group|groupDirection|activeTab|reordering|view|page|cursor)$/';

    public static function apply(Request $request, string $input = 'tableQuery', bool $withPagination = false): void
    {
        $pattern = $withPagination ? self::PAGINATED_KEY_PATTERN : self::KEY_PATTERN;
        $query = $request->input($input);

        if (! is_string($query) || $query === '') {
            return;
        }

        parse_str(ltrim($query, '?'), $parameters);

        $state = [];

        foreach ($parameters as $key => $value) {
            if (is_string($key) && preg_match($pattern, $key) === 1 && ! $request->query->has($key)) {
                $state[$key] = $value;
            }
        }

        if ($state !== []) {
            $request->query->add($state);
        }
    }
}
