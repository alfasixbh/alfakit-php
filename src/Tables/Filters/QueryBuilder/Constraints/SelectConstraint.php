<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Select rules: is / is not one of the options (`->multiple()` for several), is filled,
 * is blank. Only the constraint's own option values are accepted.
 */
class SelectConstraint extends Constraint
{
    use HasOptions;

    protected bool|Closure $isMultiple = false;

    protected bool|Closure $isSearchable = false;

    /**
     * A search box above the options that filters them as the user types.
     */
    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isSearchable);
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getType(): string
    {
        return 'select';
    }

    public function getOperators(): array
    {
        return [
            'is' => __('alfakit::tables.query_builder.operators.is'),
            'is_not' => __('alfakit::tables.query_builder.operators.is_not'),
            'is_filled' => __('alfakit::tables.query_builder.operators.is_filled'),
            'is_blank' => __('alfakit::tables.query_builder.operators.is_blank'),
        ];
    }

    protected function validateValue(string $operator, mixed $value): ?array
    {
        $allowed = array_map(strval(...), array_keys($this->getFlatOptions()));
        $values = is_array($value) ? $value : [$value];
        $values = array_values(array_unique(array_map(strval(...), array_filter($values, static fn (mixed $item): bool => is_scalar($item) && in_array((string) $item, $allowed, true)))));

        if ($values === []) {
            return null;
        }

        return ['value' => $this->isMultiple() ? $values : $values[0]];
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        $values = is_array($value) ? $value : [$value];

        match ($operator) {
            'is_filled' => $query->whereNotNull($column),
            'is_blank' => $query->whereNull($column),
            'is_not' => $query->where(static fn (Builder $q) => $q->whereNotIn($column, $values)->orWhereNull($column)),
            default => $query->whereIn($column, $values),
        };
    }

    protected function formatIndicatorValue(string $operator, mixed $value): string
    {
        $values = is_array($value) ? $value : [$value];

        return implode(', ', array_map(fn (mixed $item): string => $this->getOptionLabel($item) ?? (string) $item, $values));
    }

    protected function getInputSchemaProps(): array
    {
        return [
            'options' => $this->getSerializedOptions(),
            'multiple' => $this->isMultiple() ?: null,
            'searchable' => $this->isSearchable() ?: null,
        ];
    }
}
