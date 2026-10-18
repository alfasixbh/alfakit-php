<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionUnionType;

/**
 * Filters own a small form whose state lives under `filters.{name}` in the table query
 * string, and apply constraints to the table query based on that state.
 */
abstract class BaseFilter
{
    use Configurable;
    use EvaluatesClosures;

    protected string $name;

    protected string|Closure|null $label = null;

    protected ?Table $table = null;

    protected bool|Closure $isHidden = false;

    /** @var array<string, mixed>|Closure|null */
    protected array|Closure|null $default = null;

    protected ?Closure $query = null;

    protected ?Closure $indicateUsing = null;

    protected ?Closure $modifyBaseQueryUsing = null;

    protected ?Closure $modifyFormFieldUsing = null;

    protected bool|Closure $isExcludedWhenResolvingRecord = false;

    /** @var array<string, int|string|null>|int|string|Closure|null */
    protected array|int|string|Closure|null $columnSpan = null;

    /** @var array<string, mixed> */
    protected array $state = [];

    /** @var array<class-string> */
    protected array $hiddenOnOwners = [];

    /** @var array<class-string> */
    protected array $visibleOnOwners = [];

    final public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): static
    {
        $static = new static($name);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'filter';
    }

    public function getType(): string
    {
        return class_basename(static::class);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? Labels::fromName($this->name);
    }

    public function table(?Table $table): static
    {
        $this->table = $table;

        return $this;
    }

    public function getTable(): ?Table
    {
        return $this->table;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->isHidden) || $this->isHiddenByOwner();
    }

    /**
     * Hide when the table belongs to one of these classes (`RelationManager::class`,
     * `ListRecords::class`), so one shared table definition can differ per place.
     *
     * @param  class-string|array<class-string>  $owners
     */
    public function hiddenOn(string|array $owners): static
    {
        $this->hiddenOnOwners = (array) $owners;

        return $this;
    }

    /**
     * @param  class-string|array<class-string>  $owners
     */
    public function visibleOn(string|array $owners): static
    {
        $this->visibleOnOwners = (array) $owners;

        return $this;
    }

    protected function isHiddenByOwner(): bool
    {
        $owner = $this->getTable()?->getContext();
        $matches = static function (array $classes) use ($owner): bool {
            foreach ($classes as $class) {
                if (is_object($owner) && $owner instanceof $class) {
                    return true;
                }
            }

            return false;
        };

        return $matches($this->hiddenOnOwners) || ($this->visibleOnOwners !== [] && ! $matches($this->visibleOnOwners));
    }

    /**
     * @param  array<string, mixed>|Closure|null  $state
     */
    public function default(array|Closure|null $state): static
    {
        $this->default = $state;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDefaultState(): ?array
    {
        return $this->evaluate($this->default);
    }

    /**
     * `fn (Builder $query, array $data): Builder`
     */
    public function query(?Closure $callback): static
    {
        $this->query = $callback;

        return $this;
    }

    /**
     * Change the table's base query directly (not inside the filter's own `where` group),
     * e.g. `fn (Builder $query) => $query->withoutGlobalScopes([SoftDeletingScope::class])`.
     * Runs on every request, before the filters, with the filter's `$data`; also when an
     * action resolves its record, so records the filter shows can be acted on.
     */
    public function baseQuery(?Closure $callback): static
    {
        $this->modifyBaseQueryUsing = $callback;

        return $this;
    }

    public function applyToBaseQuery(Builder $query): Builder
    {
        if ($this->modifyBaseQueryUsing === null) {
            return $query;
        }

        $result = $this->evaluate($this->modifyBaseQueryUsing, ['query' => $query, 'data' => $this->state, 'state' => $this->state], [Builder::class => $query]);

        return $result instanceof Builder ? $result : $query;
    }

    /**
     * Filament compatibility: leave the filter's `query()` out when resolving a record for
     * an action. AlfaKit never applies filter queries there (only `baseQuery()`), so this
     * only records the intent.
     */
    public function excludeWhenResolvingRecord(bool|Closure $condition = true): static
    {
        $this->isExcludedWhenResolvingRecord = $condition;

        return $this;
    }

    public function isExcludedWhenResolvingRecord(): bool
    {
        return (bool) $this->evaluate($this->isExcludedWhenResolvingRecord);
    }

    /**
     * Customise the filter's built-in field (the checkbox / toggle of `Filter`, the select
     * of `SelectFilter`, …): `fn (Select $field) => $field->native()`. Not applied to a
     * custom `schema()`.
     */
    public function modifyFormFieldUsing(?Closure $callback): static
    {
        $this->modifyFormFieldUsing = $callback;

        return $this;
    }

    /**
     * The form components with `modifyFormFieldUsing()` applied to the built-in field.
     *
     * @return array<Component>
     */
    public function getForm(): array
    {
        $components = $this->getFormComponents();

        if ($this->modifyFormFieldUsing === null || ! $this->hasBuiltInFormField()) {
            return $components;
        }

        foreach ($components as $index => $component) {
            if (! $component instanceof Field) {
                continue;
            }

            $result = $this->evaluate($this->modifyFormFieldUsing, ['field' => $component, 'component' => $component], [Field::class => $component, $component::class => $component]);
            $components[$index] = $result instanceof Component ? $result : $component;

            break;
        }

        return $components;
    }

    /**
     * Whether the form is the filter's own field (not a custom `schema()`).
     */
    protected function hasBuiltInFormField(): bool
    {
        return true;
    }

    /**
     * `fn (array $data): string|Indicator|array|null` — the chip(s) shown for an active filter.
     */
    public function indicateUsing(?Closure $callback): static
    {
        $this->indicateUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, int|string|null>|int|string|Closure|null  $span
     */
    public function columnSpan(array|int|string|Closure|null $span): static
    {
        $this->columnSpan = $span;

        return $this;
    }

    // ---------------------------------------------------------------- State

    /**
     * @param  array<string, mixed>  $state
     */
    public function state(array $state): static
    {
        $this->state = $state;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Whether the current state filters anything.
     */
    public function isActive(): bool
    {
        foreach ($this->state as $value) {
            if (is_array($value) ? $value !== [] : ($value !== null && $value !== '' && $value !== false)) {
                return true;
            }
        }

        return false;
    }

    public function apply(Builder $query): Builder
    {
        if (! $this->isActive()) {
            return $query;
        }

        if ($this->query !== null) {
            $result = $this->evaluate($this->query, ['query' => $query, 'data' => $this->state, 'state' => $this->state], [Builder::class => $query]);

            return $result instanceof Builder ? $result : $query;
        }

        return $this->applyToQuery($query);
    }

    /**
     * Type-specific default behaviour when no `query()` closure is set.
     */
    protected function applyToQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Filter the rows of a custom-data table (`records()` without `$filters`). A `query()`
     * closure that doesn't type-hint an Eloquent builder gets the rows as `$records` (also
     * `$query` or any `Collection` parameter) plus `$data`, and returns the rows to keep:
     * `fn (Collection $records, array $data) => $records->where('status', $data['value'])`.
     * Keep the collection's keys (`filter()`, `where()`; not `values()`): they are the
     * record keys. Without a closure, the filter type's default applies.
     *
     * @param  Collection<array-key, mixed>  $records
     * @return Collection<array-key, mixed>
     */
    public function applyToRecords(Collection $records): Collection
    {
        if (! $this->isActive()) {
            return $records;
        }

        if ($this->query !== null) {
            return $this->applyClosureToRecords($this->query, $records);
        }

        return $this->applyToRecordsByDefault($records);
    }

    /**
     * Type-specific default filtering of custom-data rows when no `query()` closure is set.
     *
     * @param  Collection<array-key, mixed>  $records
     * @return Collection<array-key, mixed>
     */
    protected function applyToRecordsByDefault(Collection $records): Collection
    {
        return $records;
    }

    /**
     * @param  Collection<array-key, mixed>  $records
     * @return Collection<array-key, mixed>
     */
    protected function applyClosureToRecords(Closure $callback, Collection $records): Collection
    {
        // A closure written for an Eloquent query can't filter rows; the `records()`
        // closure can still read `$filters` and filter them itself.
        if (static::closureExpectsQuery($callback)) {
            return $records;
        }

        $result = $this->evaluate($callback, [
            'query' => $records,
            'records' => $records,
            'data' => $this->state,
            'state' => $this->state,
        ], [Collection::class => $records]);

        return match (true) {
            $result instanceof Collection => $result,
            is_array($result) => collect($result),
            default => $records,
        };
    }

    /**
     * Whether a closure type-hints an Eloquent builder or relation (so it expects a query).
     */
    protected static function closureExpectsQuery(Closure $callback): bool
    {
        foreach ((new ReflectionFunction($callback))->getParameters() as $parameter) {
            $type = $parameter->getType();
            $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

            foreach ($types as $named) {
                if (! $named instanceof ReflectionNamedType || $named->isBuiltin()) {
                    continue;
                }

                $class = $named->getName();

                if (is_a($class, Builder::class, true) || is_a($class, QueryBuilderContract::class, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string|Indicator>
     */
    public function getIndicators(): array
    {
        if (! $this->isActive()) {
            return [];
        }

        if ($this->indicateUsing !== null) {
            $indicators = $this->evaluate($this->indicateUsing, ['data' => $this->state, 'state' => $this->state]);

            return array_values(array_filter(is_array($indicators) ? $indicators : [$indicators]));
        }

        return array_values(array_filter([$this->getDefaultIndicator()]));
    }

    protected function getDefaultIndicator(): ?string
    {
        return $this->getLabel();
    }

    // ---------------------------------------------------------------- Form

    /**
     * The components rendering this filter. Keys are relative to the filter's state.
     *
     * @return array<Component>
     */
    abstract public function getFormComponents(): array;

    public function getFormSchema(): Schema
    {
        $schema = Schema::make()->components($this->getForm())->statePath($this->getName());

        if ($this->table?->getModel()) {
            $schema->model($this->table->getModel());
        }

        return $schema;
    }

    /**
     * The filter's span in the filters form, as breakpoints (`3` becomes `['default' => 3]`).
     *
     * @return array<string, int|string|null>|null
     */
    public function getColumnSpan(): ?array
    {
        $span = $this->evaluate($this->columnSpan);

        if ($span === null) {
            return null;
        }

        return is_array($span) ? $span : ['default' => $span];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return Serializable::compact([
            'type' => $this->getType(),
            'name' => $this->getName(),
            'label' => $this->getLabel(),
            'columnSpan' => $this->getColumnSpan(),
            'default' => $this->getDefaultState(),
            'schema' => $this->getFormSchema()->toSchema(),
            ...$this->getSchemaProps(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSchemaProps(): array
    {
        return [];
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'data', 'state' => [$this->state],
            'table' => [$this->table],
            default => [],
        };
    }
}
