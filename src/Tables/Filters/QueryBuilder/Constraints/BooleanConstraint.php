<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use Illuminate\Database\Eloquent\Builder;

/**
 * Boolean rules: is true, is false.
 */
class BooleanConstraint extends Constraint
{
    public function getType(): string
    {
        return 'boolean';
    }

    public function getOperators(): array
    {
        return [
            'is_true' => __('alfakit::tables.query_builder.operators.is_true'),
            'is_false' => __('alfakit::tables.query_builder.operators.is_false'),
        ];
    }

    protected function getValuelessOperators(): array
    {
        return ['is_true', 'is_false'];
    }

    protected function validateValue(string $operator, mixed $value): ?array
    {
        return ['value' => null];
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        if ($operator === 'is_true') {
            $query->where($column, true);

            return;
        }

        $query->where(static fn (Builder $q) => $q->where($column, false)->orWhereNull($column));
    }
}
