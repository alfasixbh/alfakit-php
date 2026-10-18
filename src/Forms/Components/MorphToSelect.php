<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\MorphToSelect\Type;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\TenantScope;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Edits a MorphTo relationship (`commentable`) with two selects: one for the type and
 * one for a record of that type. It is built from existing fields, so renderers draw it
 * as a fieldset (or, with `contained(false)`, a plain group) holding a `Select` (or
 * `ToggleButtons`) for `{name}_type` and a `Select` for `{name}_id`.
 *
 * Both columns are attributes of the form's record: they're filled from it, validated
 * (the type must be one of `types()`, the key must exist in that type's tenant-scoped
 * options query) and saved with the rest of its data. The stored type is the model's
 * morph class, so a morph map alias is used when one is registered.
 */
class MorphToSelect extends Component
{
    protected string $name;

    /** @var array<Type>|Closure */
    protected array|Closure $types = [];

    protected bool|Closure $isRequired = false;

    protected bool|Closure $isSearchable = false;

    protected bool|Closure $isPreloaded = false;

    protected bool|Closure $isNative = false;

    protected int|Closure $optionsLimit = 50;

    protected bool|Closure $isContained = true;

    protected bool $hasTypeToggleButtons = false;

    protected ?Closure $modifyTypeSelectUsing = null;

    protected ?Closure $modifyKeySelectUsing = null;

    protected ?Field $typeField = null;

    /** @var array<string, Select> Key selects by type alias (`''` while no type is chosen) */
    protected array $keySelects = [];

    /** @var array{0: string, 1: string}|null */
    protected ?array $columnNames = null;

    final public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    public function getName(): string
    {
        return $this->name;
    }

    // ---------------------------------------------------------------- Configuration

    /**
     * @param  array<Type>|Closure  $types
     */
    public function types(array|Closure $types): static
    {
        $this->types = $types;

        return $this;
    }

    public function required(bool|Closure $condition = true): static
    {
        $this->isRequired = $condition;

        return $this;
    }

    public function searchable(bool|Closure $condition = true): static
    {
        $this->isSearchable = $condition;

        return $this;
    }

    /**
     * Load every option of the chosen type up front instead of searching on demand.
     */
    public function preload(bool|Closure $condition = true): static
    {
        $this->isPreloaded = $condition;

        return $this;
    }

    public function native(bool|Closure $condition = true): static
    {
        $this->isNative = $condition;

        return $this;
    }

    public function optionsLimit(int|Closure $limit): static
    {
        $this->optionsLimit = $limit;

        return $this;
    }

    /**
     * Draw the selects in a fieldset with the label as its legend (default), or without
     * a border.
     */
    public function contained(bool|Closure $condition = true): static
    {
        $this->isContained = $condition;

        return $this;
    }

    /**
     * Choose the type with inline toggle buttons instead of a select.
     */
    public function typeSelectToggleButtons(bool $condition = true): static
    {
        $this->hasTypeToggleButtons = $condition;
        $this->typeField = null;

        return $this;
    }

    /**
     * `fn (Select $select): Select` (or `ToggleButtons` with `typeSelectToggleButtons()`).
     */
    public function modifyTypeSelectUsing(?Closure $callback): static
    {
        $this->modifyTypeSelectUsing = $callback;
        $this->typeField = null;

        return $this;
    }

    /**
     * `fn (Select $select): Select`, applied to the key select of every type (before the
     * type's own `modifyKeySelectUsing()`).
     */
    public function modifyKeySelectUsing(?Closure $callback): static
    {
        $this->modifyKeySelectUsing = $callback;
        $this->keySelects = [];

        return $this;
    }

    // ---------------------------------------------------------------- Resolution

    /**
     * @return array<Type>
     */
    public function getTypes(): array
    {
        return array_values(array_filter($this->evaluate($this->types) ?? [], static fn (mixed $type): bool => $type instanceof Type));
    }

    public function isRequired(): bool
    {
        return (bool) $this->evaluate($this->isRequired);
    }

    public function getType(): string
    {
        return $this->type ?? ($this->evaluate($this->isContained) ? 'Fieldset' : 'Group');
    }

    /**
     * The morph type and foreign key columns: those of the model's MorphTo relationship
     * when it has one, else `{name}_type` and `{name}_id`.
     *
     * @return array{0: string, 1: string}
     */
    protected function getColumnNames(): array
    {
        if ($this->columnNames !== null) {
            return $this->columnNames;
        }

        $model = $this->hasContainer() ? $this->getModelInstance() : null;

        if ($model !== null && method_exists($model, $this->name) && ($relationship = $model->{$this->name}()) instanceof MorphTo) {
            return $this->columnNames = [$relationship->getMorphType(), $relationship->getForeignKeyName()];
        }

        $columns = ["{$this->name}_type", "{$this->name}_id"];

        // Without a container the model may still come: don't remember the guess.
        if ($model !== null) {
            $this->columnNames = $columns;
        }

        return $columns;
    }

    public function getTypeColumn(): string
    {
        return $this->getColumnNames()[0];
    }

    public function getKeyColumn(): string
    {
        return $this->getColumnNames()[1];
    }

    protected function getColumnStatePath(string $column): string
    {
        $prefix = $this->getStatePath();

        return filled($prefix) ? "{$prefix}.{$column}" : $column;
    }

    /**
     * The type whose alias is in the type column's current state.
     */
    public function getSelectedType(): ?Type
    {
        $value = $this->getContainer()->getStateAt($this->getColumnStatePath($this->getTypeColumn()));

        if (! is_string($value) || $value === '') {
            return null;
        }

        foreach ($this->getTypes() as $type) {
            if ($type->getAlias() === $value) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function getTypeOptions(): array
    {
        $options = [];

        foreach ($this->getTypes() as $type) {
            $options[$type->getAlias()] = $type->getLabelUsing(fn (mixed $value): mixed => $this->evaluate($value));
        }

        return $options;
    }

    /**
     * The tenant-scoped query listing the records of a type.
     *
     * @return Builder<Model>
     */
    public function getKeyQuery(Type $type): Builder
    {
        $model = $type->getModel();
        $query = TenantScope::apply((new $model)->newQuery());

        if (($modify = $type->getModifyOptionsQueryCallback()) !== null) {
            $query = $this->evaluate($modify, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }

    /**
     * @return array<string, string>
     */
    public function getKeyOptions(Type $type, ?string $search = null, bool $applyLimit = true): array
    {
        $query = $this->getKeyQuery($type);
        $title = $type->getTitleAttributeUsing(fn (mixed $value): mixed => $this->evaluate($value));
        $keyName = $query->getModel()->getKeyName();

        if (filled($search)) {
            $columns = $type->getSearchColumns() ?? [$title ?? $keyName];

            $query->where(static function (Builder $query) use ($columns, $search): void {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', "%{$search}%");
                }
            });
        }

        if ($applyLimit) {
            $query->limit((int) $this->evaluate($this->optionsLimit));
        }

        if ($title !== null && ($query->getQuery()->orders === null || $query->getQuery()->orders === [])) {
            $query->orderBy($title);
        }

        return $this->labelRecords($type, $query->get()->all());
    }

    /**
     * Labels of the given keys, only for records the type's options query contains.
     *
     * @param  array<mixed>  $values
     * @return array<string, string>
     */
    public function getKeyLabels(Type $type, array $values): array
    {
        $values = array_values(array_filter($values, static fn (mixed $value): bool => is_scalar($value) && $value !== ''));

        if ($values === []) {
            return [];
        }

        $query = $this->getKeyQuery($type);

        return $this->labelRecords($type, $query->whereIn($query->qualifyColumn($query->getModel()->getKeyName()), $values)->get()->all());
    }

    /**
     * @param  array<int, Model>  $records
     * @return array<string, string>
     */
    protected function labelRecords(Type $type, array $records): array
    {
        $title = $type->getTitleAttributeUsing(fn (mixed $value): mixed => $this->evaluate($value));
        $callback = $type->getOptionLabelFromRecordCallback();
        $labels = [];

        foreach ($records as $record) {
            $labels[(string) $record->getKey()] = $callback !== null
                ? (string) $this->evaluate($callback, ['record' => $record], [Model::class => $record, $record::class => $record])
                : (string) $record->getAttribute($title ?? $record->getKeyName());
        }

        return $labels;
    }

    // ---------------------------------------------------------------- Children

    /**
     * The type field and the key select of the chosen type. Re-read on every access, so
     * a live type change in the same request shows the new type's select.
     *
     * @return array<Component>
     */
    public function getChildComponents(): array
    {
        return [$this->getTypeField(), $this->getKeySelect($this->getSelectedType())];
    }

    public function hasChildComponents(): bool
    {
        return true;
    }

    public function getChildSchema(?string $statePath = null): Schema
    {
        if ($this->childSchema !== null && $statePath === null) {
            return $this->childSchema;
        }

        $schema = Schema::make()
            ->parentComponent($this)
            ->components(fn (): array => $this->getChildComponents());

        if (filled($path = $statePath ?? $this->getStatePath())) {
            $schema->statePath($path);
        }

        if ($statePath === null) {
            $this->childSchema = $schema;
        }

        return $schema;
    }

    public function getTypeField(): Field
    {
        if ($this->typeField !== null) {
            return $this->typeField;
        }

        $column = $this->getTypeColumn();
        $keyColumn = $this->getKeyColumn();

        $field = $this->hasTypeToggleButtons
            ? ToggleButtons::make($column)->inline()
            : Select::make($column)->native(fn (): bool => (bool) $this->evaluate($this->isNative));

        $field
            ->label(__('alfakit::forms.morph_to_select.type'))
            ->options(fn (): array => $this->getTypeOptions())
            ->required(fn (): bool => $this->isRequired())
            ->live()
            // Records stored with the class name while a morph map alias exists now.
            ->afterStateHydrated(function (mixed $state, Set $set) use ($column): void {
                foreach ($this->getTypes() as $type) {
                    if ($state === $type->getModel() && $state !== $type->getAlias()) {
                        $set($column, $type->getAlias());
                    }
                }
            })
            ->afterStateUpdated(static fn (Set $set): mixed => $set($keyColumn, null));

        if ($this->modifyTypeSelectUsing !== null) {
            $field = $this->evaluate($this->modifyTypeSelectUsing, ['select' => $field, 'toggleButtons' => $field, 'component' => $field], [$field::class => $field, Field::class => $field]) ?? $field;
        }

        return $this->typeField = $field;
    }

    public function getKeySelect(?Type $type): Select
    {
        $alias = $type?->getAlias() ?? '';

        if (isset($this->keySelects[$alias])) {
            return $this->keySelects[$alias];
        }

        $select = Select::make($this->getKeyColumn())
            ->label($type === null ? $this->getLabel() : $type->getLabelUsing(fn (mixed $value): mixed => $this->evaluate($value)))
            ->required(fn (): bool => $this->isRequired())
            ->searchable(fn (): bool => (bool) $this->evaluate($this->isSearchable))
            ->preload(fn (): bool => (bool) $this->evaluate($this->isPreloaded))
            ->native(fn (): bool => (bool) $this->evaluate($this->isNative))
            ->optionsLimit(fn (): int => (int) $this->evaluate($this->optionsLimit))
            ->options(function () use ($type): array {
                if ($type === null) {
                    return [];
                }

                $searchable = (bool) $this->evaluate($this->isSearchable);

                if ($searchable && ! $this->evaluate($this->isPreloaded)) {
                    return [];
                }

                return $this->getKeyOptions($type, applyLimit: $searchable);
            })
            ->getSearchResultsUsing(fn (string $search): array => $type === null ? [] : $this->getKeyOptions($type, $search))
            // Also the validation rule: a submitted key must be a record of the options query.
            ->getOptionLabelsUsing(fn (array $values): array => $type === null ? [] : $this->getKeyLabels($type, $values));

        if ($type === null) {
            // Until a type is chosen there's nothing to pick; the key is cleared on save so
            // a cleared type never leaves a dangling id.
            $select
                ->hidden()
                ->dehydratedWhenHidden(fn (): bool => ! $this->isHiddenInTree())
                ->dehydrateStateUsing(static fn (): mixed => null);
        }

        if ($this->modifyKeySelectUsing !== null) {
            $select = $this->evaluate($this->modifyKeySelectUsing, ['select' => $select, 'component' => $select, 'type' => $type], [Select::class => $select]) ?? $select;
        }

        if (($modify = $type?->getModifyKeySelectCallback()) !== null) {
            $select = $this->evaluate($modify, ['select' => $select, 'component' => $select, 'type' => $type], [Select::class => $select]) ?? $select;
        }

        return $this->keySelects[$alias] = $select;
    }

    public function flushChildSchema(): static
    {
        $this->typeField = null;
        $this->keySelects = [];
        $this->columnNames = null;

        return parent::flushChildSchema();
    }

    public function __clone(): void
    {
        parent::__clone();

        // Children close over their owner: a clone (a repeater item) builds its own.
        $this->typeField = null;
        $this->keySelects = [];
        $this->columnNames = null;
    }

    /**
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1, 'lg' => 2];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->columnSpanFull();
    }

    public function toSchema(): array
    {
        $schema = parent::toSchema();

        // A plain group carries no legend.
        if ($this->getType() === 'Group') {
            unset($schema['label']);
        }

        return $schema;
    }
}
