<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Builder;

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
     * @param  Builder<*>  $query
     */
    public static function contains(Builder $query, string $column, string $search, bool $or = false, bool $caseInsensitive = false): void
    {
        $driver = $query->getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $sql = 'cast('.$query->getQuery()->getGrammar()->wrap($column).' as text) ilike ?';
            $query->{$or ? 'orWhereRaw' : 'whereRaw'}($sql, ["%{$search}%"]);

            return;
        }

        if ($caseInsensitive) {
            $sql = 'lower('.$query->getQuery()->getGrammar()->wrap($column).') like ?';
            $query->{$or ? 'orWhereRaw' : 'whereRaw'}($sql, ['%'.mb_strtolower($search).'%']);

            return;
        }

        $query->{$or ? 'orWhere' : 'where'}($column, 'like', "%{$search}%");
    }
}
