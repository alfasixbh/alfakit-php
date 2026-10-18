<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `unique` through the Eloquent model: its global scopes (soft deletes, tenancy) apply, and
 * `$modifyQueryUsing` may change the query, e.g. remove a scope. Built by
 * `Field::scopedUnique()`.
 */
final class ScopedUnique implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     * @param  Closure(Builder<Model>): mixed|null  $modifyQueryUsing
     */
    public function __construct(
        private readonly string $model,
        private readonly string $column,
        private readonly ?Model $ignorable = null,
        private readonly ?Closure $modifyQueryUsing = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        /** @var Model $instance */
        $instance = new ($this->model);
        $query = $instance->newQuery()->where($instance->qualifyColumn($this->column), $value);

        if ($this->ignorable !== null && $this->ignorable->getKey() !== null) {
            $query->whereKeyNot($this->ignorable->getOriginal($this->ignorable->getKeyName()) ?? $this->ignorable->getKey());
        }

        if ($this->modifyQueryUsing !== null) {
            $query = ($this->modifyQueryUsing)($query) ?? $query;
        }

        if ($query instanceof Builder && $query->exists()) {
            $fail('validation.unique')->translate();
        }
    }
}
