<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Contains" searches that behave the same on every database.
 */
class SearchQuery
{
    /**
     * Add `column like %search%` to the query.
     *
     * PostgreSQL's `like` is case-sensitive and refuses a number or date column, so there the
     * column is cast to text and matched with `ilike`. MySQL and SQLite coerce the column and
     * follow its collation; `$caseInsensitive` lowers both sides for a case-sensitive one.
     *
     * @param  Builder<*>|QueryBuilder  $query
     */
    public static function contains(Builder|QueryBuilder $query, string $column, string $search, bool $or = false, bool $caseInsensitive = false): void
    {
        $base = $query instanceof Builder ? $query->getQuery() : $query;
        $driver = $base->getConnection()->getDriverName();
        $wrapped = $base->getGrammar()->wrap($column);
        // `%` and `_` in the search are text, not wildcards ("50%" finds "50%", not "500").
        $pattern = '%'.self::escape($caseInsensitive && $driver !== 'pgsql' ? mb_strtolower($search) : $search).'%';

        $sql = match (true) {
            $driver === 'pgsql' => "cast({$wrapped} as text) ilike ? escape '!'",
            $caseInsensitive => "lower({$wrapped}) like ? escape '!'",
            default => "{$wrapped} like ? escape '!'",
        };

        $query->{$or ? 'orWhereRaw' : 'whereRaw'}($sql, [$pattern]);
    }

    /**
     * Escape LIKE wildcards with `!` (the escape character `contains()` declares).
     */
    public static function escape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
