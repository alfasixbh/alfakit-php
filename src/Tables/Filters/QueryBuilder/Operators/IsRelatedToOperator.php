<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators;

use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Closure;

/**
 * Settings of a relationship constraint's "has" operator, in Filament's form:
 *
 * ```php
 * RelationshipConstraint::make('creator')
 *     ->selectable(
 *         IsRelatedToOperator::make()
 *             ->titleAttribute('name')
 *             ->searchable()
 *             ->multiple(),
 *     )
 * ```
 *
 * The settings are copied onto the constraint (the same as calling `titleAttribute()`,
 * `searchable()`, `multiple()`, `optionsLimit()` and `modifyOptionsQueryUsing()` on it).
 */
class IsRelatedToOperator
{
    protected string|Closure|null $titleAttribute = null;

    protected bool|Closure|null $isMultiple = null;

    protected bool|Closure|null $isSearchable = null;

    protected int|Closure|null $optionsLimit = null;

    protected ?Closure $modifyQueryUsing = null;

    final public function __construct() {}

    public static function make(): static
    {
        return new static;
    }

    public function titleAttribute(string|Closure $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    /**
     * `fn (Builder $query) => $query->where(…)`: narrow the selectable related records.
     */
    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * Filament's `relationship()` form: only the title attribute and query apply (the
     * relationship is the constraint's).
     */
    public function relationship(?string $name = null, string|Closure|null $titleAttribute = null, ?Closure $modifyQueryUsing = null): static
    {
        if ($titleAttribute !== null) {
            $this->titleAttribute = $titleAttribute;
        }

        if ($modifyQueryUsing !== null) {
            $this->modifyQueryUsing = $modifyQueryUsing;
        }

        return $this;
    }

    public function configureConstraint(RelationshipConstraint $constraint): void
    {
        if ($this->titleAttribute !== null) {
            $constraint->titleAttribute($this->titleAttribute);
        }

        if ($this->isMultiple !== null) {
            $constraint->multiple($this->isMultiple);
        }

        if ($this->isSearchable !== null) {
            $constraint->searchable($this->isSearchable);
        }

        if ($this->optionsLimit !== null) {
            $constraint->optionsLimit($this->optionsLimit);
        }

        if ($this->modifyQueryUsing !== null) {
            $constraint->modifyOptionsQueryUsing($this->modifyQueryUsing);
        }
    }
}
