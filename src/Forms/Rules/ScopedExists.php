<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `exists` through the Eloquent model: its global scopes (soft deletes, tenancy) apply, and
 * `$modifyQueryUsing` may change the query, e.g. remove a scope. Arrays (a multiple
 * select) must exist entirely. Built by `Field::scopedExists()`.
 */
final class ScopedExists implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Builder<Model>): mixed|null  $modifyQueryUsing
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
        private readonly ?Closure $modifyQueryUsing = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $values = array_values(array_unique(array_map(static fn (mixed $item): string => (string) (is_scalar($item) ? $item : ''), is_array($value) ? $value : [$value])));

        /** @var Model $instance */
        $instance = new ($this->model);
        $query = $instance->newQuery()->whereIn($instance->qualifyColumn($this->column), $values);

        if ($this->modifyQueryUsing !== null) {
            $query = ($this->modifyQueryUsing)($query) ?? $query;
        }

        if (! $query instanceof Builder || $query->distinct()->count($instance->qualifyColumn($this->column)) < count($values)) {
            $fail('validation.exists')->translate();
        }
    }
}
