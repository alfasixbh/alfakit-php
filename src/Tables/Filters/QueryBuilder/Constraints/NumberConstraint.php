<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Number rules: equals, does not equal, greater / less than (or equal), is filled, is blank.
 */
class NumberConstraint extends Constraint
{
    protected bool|Closure $isInteger = false;

    public function integer(bool|Closure $condition = true): static
    {
        $this->isInteger = $condition;

        return $this;
    }

    public function getType(): string
    {
        return 'number';
    }

    public function getOperators(): array
    {
        return [
            'equals' => __('alfakit::tables.query_builder.operators.equals'),
            'not_equals' => __('alfakit::tables.query_builder.operators.not_equals'),
            'gt' => __('alfakit::tables.query_builder.operators.gt'),
            'gte' => __('alfakit::tables.query_builder.operators.gte'),
            'lt' => __('alfakit::tables.query_builder.operators.lt'),
            'lte' => __('alfakit::tables.query_builder.operators.lte'),
            'is_filled' => __('alfakit::tables.query_builder.operators.is_filled'),
            'is_blank' => __('alfakit::tables.query_builder.operators.is_blank'),
        ];
    }

    protected function validateValue(string $operator, mixed $value): ?array
    {
        if (! is_scalar($value) || ! is_numeric($value) || ! is_finite((float) $value)) {
            return null;
        }

        if ($this->evaluate($this->isInteger)) {
            return filter_var($value, FILTER_VALIDATE_INT) === false ? null : ['value' => (int) $value];
        }

        return ['value' => (float) $value];
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            'is_filled' => $query->whereNotNull($column),
            'is_blank' => $query->whereNull($column),
            'not_equals' => $query->where(static fn (Builder $q) => $q->where($column, '!=', $value)->orWhereNull($column)),
            default => $query->where($column, match ($operator) {
                'gt' => '>',
                'gte' => '>=',
                'lt' => '<',
                'lte' => '<=',
                default => '=',
            }, $value),
        };
    }

    protected function formatIndicatorValue(string $operator, mixed $value): string
    {
        return Number::format((float) $value, maxPrecision: 10) ?: (string) $value;
    }

    protected function getInputSchemaProps(): array
    {
        return ['step' => $this->evaluate($this->isInteger) ? 1 : null];
    }
}
