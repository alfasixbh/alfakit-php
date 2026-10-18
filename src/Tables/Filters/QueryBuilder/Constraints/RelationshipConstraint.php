<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints;

use AlfaSix\Alfakit\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Relationship rules: has one of the selected records, has at least / at most N related
 * records, has none, has some.
 *
 * ```php
 * RelationshipConstraint::make('tags')->titleAttribute('name')->multiple()
 * ```
 *
 * The options list the related model's records (tenant-scoped, at most `optionsLimit()`),
 * labelled by the title attribute.
 */
class RelationshipConstraint extends Constraint
{
    protected string|Closure $titleAttribute = 'name';

    protected bool|Closure $isMultiple = false;

    protected int|Closure $optionsLimit = 100;

    protected ?Closure $modifyOptionsQueryUsing = null;

    /** @var array<string, string>|null */
    protected ?array $cachedOptions = null;

    protected ?Model $model = null;

    protected bool|Closure $isEmptyable = true;

    protected bool|Closure $isSearchable = false;

    public function titleAttribute(string|Closure $attribute): static
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    /**
     * Offer the "has none" / "has some" operators (on by default in AlfaKit; Filament
     * leaves them out unless `emptyable()`). `emptyable(false)` removes them.
     */
    public function emptyable(bool|Closure $condition = true): static
    {
        $this->isEmptyable = $condition;

        return $this;
    }

    public function isEmptyable(): bool
    {
        return (bool) $this->evaluate($this->isEmptyable);
    }

    /**
     * A search box above the related records of the "has" operator; it filters the
     * loaded options (at most `optionsLimit()`).
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

    /**
     * Filament's form: configure the "has" operator with an `IsRelatedToOperator`
     * (`titleAttribute()`, `multiple()`, `searchable()`, `optionsLimit()`,
     * `modifyQueryUsing()`), copied onto this constraint.
     */
    public function selectable(\AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators\IsRelatedToOperator $operator): static
    {
        $operator->configureConstraint($this);

        return $this;
    }

    public function multiple(bool|Closure $condition = true): static
    {
        $this->isMultiple = $condition;

        return $this;
    }

    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    /**
     * `fn (Builder $query) => $query->where(…)` — narrow the selectable related records.
     */
    public function modifyOptionsQueryUsing(?Closure $callback): static
    {
        $this->modifyOptionsQueryUsing = $callback;
        $this->cachedOptions = null;

        return $this;
    }

    /**
     * The table's model, so the relationship (and its options) can be resolved.
     */
    public function model(?Model $model): static
    {
        $this->model = $model;
        $this->cachedOptions = null;

        return $this;
    }

    public function isMultiple(): bool
    {
        return (bool) $this->evaluate($this->isMultiple);
    }

    public function getRelationshipName(): string
    {
        return $this->evaluate($this->relationshipName) ?? $this->getName();
    }

    public function getType(): string
    {
        return 'relationship';
    }

    public function getOperators(): array
    {
        return [
            'has' => __('alfakit::tables.query_builder.operators.has'),
            'has_min' => __('alfakit::tables.query_builder.operators.has_min'),
            'has_max' => __('alfakit::tables.query_builder.operators.has_max'),
            ...($this->isEmptyable() ? [
                'is_empty' => __('alfakit::tables.query_builder.operators.is_empty'),
                'is_not_empty' => __('alfakit::tables.query_builder.operators.is_not_empty'),
            ] : []),
        ];
    }

    protected function getValuelessOperators(): array
    {
        return ['is_empty', 'is_not_empty'];
    }

    protected function validateValue(string $operator, mixed $value): ?array
    {
        if ($operator === 'has_min' || $operator === 'has_max') {
            $count = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1_000_000]]) : false;

            return $count === false ? null : ['value' => $count];
        }

        $values = is_array($value) ? $value : [$value];
        $values = array_values(array_unique(array_map(strval(...), array_filter($values, static fn (mixed $item): bool => is_scalar($item) && (string) $item !== ''))));
        $values = array_slice($values, 0, 100);

        if ($values === []) {
            return null;
        }

        return ['value' => $this->isMultiple() ? $values : [$values[0]]];
    }

    public function apply(Builder $query, string $operator, mixed $value): void
    {
        $relationship = $this->getRelationshipName();

        if (($custom = $this->getCustomOperator($operator)) !== null) {
            $query->whereHas($relationship, function (Builder $related) use ($custom, $value): void {
                $this->modifyRelationshipQuery($related);
                $custom->apply($related, $related->getModel()->getQualifiedKeyName(), $this, $value);
            });

            return;
        }

        match ($operator) {
            'is_empty' => $query->whereDoesntHave($relationship, fn (Builder $q) => $this->modifyRelationshipQuery($q)),
            'is_not_empty' => $query->whereHas($relationship, fn (Builder $q) => $this->modifyRelationshipQuery($q)),
            'has_min' => $query->whereHas($relationship, fn (Builder $q) => $this->modifyRelationshipQuery($q), '>=', (int) $value),
            // "At most N" includes records with none.
            'has_max' => $query->whereHas($relationship, fn (Builder $q) => $this->modifyRelationshipQuery($q), '<=', (int) $value)
                ->orWhereDoesntHave($relationship, fn (Builder $q) => $this->modifyRelationshipQuery($q)),
            default => $query->whereHas($relationship, function (Builder $related) use ($value): void {
                $this->modifyRelationshipQuery($related);
                $related->whereKey((array) $value);
            }),
        };
    }

    protected function applyOperator(Builder $query, string $column, string $operator, mixed $value): void
    {
        // Relationship rules are applied by `apply()` directly.
    }

    /**
     * Related records offered in the value select, `key => title`.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        if ($this->cachedOptions !== null) {
            return $this->cachedOptions;
        }

        $related = $this->getRelatedQuery();

        if ($related === null) {
            return $this->cachedOptions = [];
        }

        $title = (string) $this->evaluate($this->titleAttribute);
        $model = $related->getModel();

        return $this->cachedOptions = $related
            ->orderBy($related->qualifyColumn($title))
            ->limit((int) $this->evaluate($this->optionsLimit))
            ->get([$model->getQualifiedKeyName(), $related->qualifyColumn($title)])
            ->mapWithKeys(static fn (Model $record): array => [(string) $record->getKey() => (string) $record->getAttribute($title)])
            ->all();
    }

    protected function getRelatedQuery(): ?Builder
    {
        $relationshipName = $this->getRelationshipName();

        if ($this->model === null || ! method_exists($this->model, $relationshipName)) {
            return null;
        }

        $relationship = $this->model->{$relationshipName}();

        if (! $relationship instanceof Relation) {
            return null;
        }

        $query = TenantScope::apply($relationship->getRelated()->newQuery());

        if ($this->modifyOptionsQueryUsing !== null) {
            $query = $this->evaluate($this->modifyOptionsQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }

    protected function formatIndicatorValue(string $operator, mixed $value): string
    {
        if ($operator === 'has_min' || $operator === 'has_max') {
            return (string) $value;
        }

        $keys = array_map(strval(...), (array) $value);
        $labels = [];
        $related = $this->getRelatedQuery();

        if ($related !== null) {
            $title = (string) $this->evaluate($this->titleAttribute);

            // Labels come from the scoped query, so keys from elsewhere show as themselves.
            $labels = $related->whereKey($keys)->get()
                ->mapWithKeys(static fn (Model $record): array => [(string) $record->getKey() => (string) $record->getAttribute($title)])
                ->all();
        }

        return implode(', ', array_map(static fn (string $key): string => $labels[$key] ?? $key, $keys));
    }

    protected function getOperatorsSchema(): array
    {
        $operators = [];

        foreach ($this->getAvailableOperators() as $name => $label) {
            $operators[] = $this->getCustomOperator($name) !== null ? $this->getOperatorSchema($name, $label) : [
                'name' => $name,
                'label' => $label,
                'input' => match ($name) {
                    'has' => 'select',
                    'has_min', 'has_max' => 'number',
                    default => 'none',
                },
            ];
        }

        return $operators;
    }

    protected function getInputSchemaProps(): array
    {
        $options = [];

        foreach ($this->getOptions() as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => $label];
        }

        return [
            'options' => $options,
            'multiple' => $this->isMultiple() ?: null,
            'searchable' => $this->isSearchable() ?: null,
            'step' => 1,
        ];
    }
}
