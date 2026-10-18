<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * Text rules: contains, starts with, ends with, equals (each with its negation), is
 * filled and is blank. Matching is case-insensitive on every database.
 */
class TextConstraint extends Constraint
{
    public const MAX_LENGTH = 255;

    public function getType(): string
    {
        return 'text';
    }

    public function getOperators(): array
    {
        return [
            'contains' => __('alfakit::tables.query_builder.operators.contains'),
            'not_contains' => __('alfakit::tables.query_builder.operators.not_contains'),
            'starts_with' => __('alfakit::tables.query_builder.operators.starts_with'),
            'not_starts_with' => __('alfakit::tables.query_builder.operators.not_starts_with'),
            'ends_with' => __('alfakit::tables.query_builder.operators.ends_with'),
            'not_ends_with' => __('alfakit::tables.query_builder.operators.not_ends_with'),
            'equals' => __('alfakit::tables.query_builder.operators.equals'),
            'not_equals' => __('alfakit::tables.query_builder.operators.not_equals'),
            'is_filled' => __('alfakit::tables.query_builder.operators.is_filled'),
            'is_blank' => __('alfakit::tables.query_builder.operators.is_blank'),
        ];
    }

    protected function validateValue(string $operator, mixed $value): ?array
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || mb_strlen($value) > self::MAX_LENGTH ? null : ['value' => $value];
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        if ($operator === 'is_filled') {
            $query->whereNotNull($column)->where($column, '!=', '');

            return;
        }

        if ($operator === 'is_blank') {
            $query->where(static fn (Builder $q) => $q->whereNull($column)->orWhere($column, '=', ''));

            return;
        }

        $negated = str_starts_with($operator, 'not_');
        $pattern = match ($negated ? substr($operator, 4) : $operator) {
            'contains' => "%{$value}%",
            'starts_with' => "{$value}%",
            'ends_with' => "%{$value}",
            default => (string) $value,
        };

        $lower = 'lower('.$query->getQuery()->getGrammar()->wrap($column).')';
        $comparison = $operator === 'equals' || $operator === 'not_equals' ? ['=', '!='] : ['like', 'not like'];
        $sql = $lower.' '.($negated ? $comparison[1] : $comparison[0]).' ?';

        if ($negated) {
            // Records without a value don't contain the text either.
            $query->where(static fn (Builder $q) => $q->whereRaw($sql, [mb_strtolower($pattern)])->orWhereNull($column));

            return;
        }

        $query->whereRaw($sql, [mb_strtolower($pattern)]);
    }

    protected function formatIndicatorValue(string $operator, mixed $value): string
    {
        return (string) $value;
    }
}
