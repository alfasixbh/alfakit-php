<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\HasRepeaterItemActions;
use AlfaSix\Alfakit\Forms\Components\Concerns\HasTableLayout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Concerns\CanDeferLoading;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Concerns\CanBeVirtualized;
use AlfaSix\Alfakit\Support\TenantScope;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;

/**
 * A list of sub-forms. Each item is hydrated, validated and dehydrated through its own
 * child schema (with cloned components) whose state path is `{name}.{index}`.
 */
class Repeater extends Field
{
    use CanBeVirtualized;
    use CanDeferLoading;
    use HasRepeaterItemActions;
    use HasTableLayout;

    public const TEMPLATE_INDEX = '__INDEX__';

    protected static bool $isFaked = false;

    protected int|Closure|null $minItems = null;

    protected int|Closure|null $maxItems = null;

    protected int|Closure $defaultItems = 1;

    protected bool|Closure $isAddable = true;

    protected bool|Closure $isDeletable = true;

    protected bool|Closure $isReorderable = true;

    protected bool|Closure $isCloneable = false;

    protected bool|Closure $isCollapsible = false;

    protected bool|Closure $isCollapsed = false;

    protected string|Closure|null $addActionLabel = null;

    protected string|Closure|null $itemLabel = null;

    protected ?Closure $itemLabelUsing = null;

    /** @var int|array<string, int|null>|Closure|null */
    protected int|array|Closure|null $grid = null;

    protected bool|Closure $isCompact = false;

    protected ?Field $simpleField = null;

    protected string|Closure|null $relationshipName = null;

    protected ?Closure $modifyRelationshipQueryUsing = null;

    protected ?Closure $modifyRelationshipRecordsUsing = null;

    protected ?Closure $afterCreate = null;

    protected ?Closure $afterUpdate = null;

    protected ?Closure $afterDelete = null;

    protected string|Closure|null $orderColumn = null;

    protected ?Closure $mutateRelationshipDataBeforeCreateUsing = null;

    protected ?Closure $mutateRelationshipDataBeforeSaveUsing = null;

    protected ?Closure $mutateRelationshipDataBeforeFillUsing = null;

    /** @var array<string, Schema> */
    protected array $itemSchemas = [];

    /** @var array<string, Model>|null */
    protected ?array $relatedRecords = null;

    /** @var array<string, string|null> Item index => related key its schema's record was bound for */
    protected array $boundItemKeys = [];

    // ---------------------------------------------------------------- Configuration

    public function minItems(int|Closure|null $count): static
    {
        $this->minItems = $count;

        return $this;
    }

    public function maxItems(int|Closure|null $count): static
    {
        $this->maxItems = $count;

        return $this;
    }

    public function defaultItems(int|Closure $count): static
    {
        $this->defaultItems = $count;

        return $this;
    }

    public function addable(bool|Closure $condition = true): static
    {
        $this->isAddable = $condition;

        return $this;
    }

    public function deletable(bool|Closure $condition = true): static
    {
        $this->isDeletable = $condition;

        return $this;
    }

    public function reorderable(bool|Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    public function cloneable(bool|Closure $condition = true): static
    {
        $this->isCloneable = $condition;

        return $this;
    }

    public function collapsible(bool|Closure $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    public function collapsed(bool|Closure $condition = true): static
    {
        $this->isCollapsed = $condition;

        return $this->collapsible();
    }

    public function addActionLabel(string|Closure|null $label): static
    {
        $this->addActionLabel = $label;

        return $this;
    }

    /**
     * A static label, or a closure receiving the item `$state` and `$index`.
     */
    public function itemLabel(string|Closure|null $label): static
    {
        if ($label instanceof Closure) {
            $this->itemLabelUsing = $label;
            $this->itemLabel = null;
        } else {
            $this->itemLabel = $label;
            $this->itemLabelUsing = null;
        }

        return $this;
    }

    /**
     * A table layout (`table()`) with tighter cells, to fit more rows.
     */
    public function compact(bool|Closure $condition = true): static
    {
        $this->isCompact = $condition;

        return $this;
    }

    public function isCompact(): bool
    {
        return (bool) $this->evaluate($this->isCompact);
    }

    /**
     * @param  int|array<string, int|null>|Closure|null  $columns
     */
    public function grid(int|array|Closure|null $columns = 2): static
    {
        $this->grid = $columns;

        return $this;
    }

    /**
     * Render a single field per item and store a flat list of values.
     */
    public function simple(Field $field): static
    {
        $this->simpleField = $field;

        return $this->schema([$field]);
    }

    /**
     * Manage a HasMany / MorphMany relationship (items are related records, e.g. a
     * HasMany to a pivot model) or a BelongsToMany (items are pivot rows: the item field
     * named after the related pivot key, such as `product_id`, picks the related record
     * and the other item fields are pivot columns).
     *
     * `modifyQueryUsing` receives the relationship's `$query`; `modifyRecordsUsing`
     * receives the loaded `Collection $records` and returns the ones this repeater
     * manages. Records left out are neither shown nor updated or deleted.
     */
    public function relationship(string|Closure|null $name = null, ?Closure $modifyQueryUsing = null, ?Closure $modifyRecordsUsing = null): static
    {
        $this->relationshipName = $name ?? fn (): string => $this->getName();
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;
        $this->modifyRelationshipRecordsUsing = $modifyRecordsUsing;

        return $this;
    }

    /**
     * Runs after a relationship item was created: `fn (array $data, Model $record)`. For
     * a BelongsToMany repeater `$record` is the attached related record.
     */
    public function afterCreate(?Closure $callback): static
    {
        $this->afterCreate = $callback;

        return $this;
    }

    /**
     * Runs after an existing relationship item was saved: `fn (array $data, Model $record)`
     * (the pivot record for a BelongsToMany repeater).
     */
    public function afterUpdate(?Closure $callback): static
    {
        $this->afterUpdate = $callback;

        return $this;
    }

    /**
     * Runs after a relationship item was deleted (detached, for a BelongsToMany
     * repeater): `fn (Model $record)`. The record's attributes are still readable.
     */
    public function afterDelete(?Closure $callback): static
    {
        $this->afterDelete = $callback;

        return $this;
    }

    public function orderColumn(string|Closure|null $column = 'sort'): static
    {
        $this->orderColumn = $column;

        return $this->reorderable();
    }

    public function mutateRelationshipDataBeforeCreateUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeCreateUsing = $callback;

        return $this;
    }

    public function mutateRelationshipDataBeforeSaveUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeSaveUsing = $callback;

        return $this;
    }

    public function mutateRelationshipDataBeforeFillUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeFillUsing = $callback;

        return $this;
    }

    // ---------------------------------------------------------------- Testing

    /**
     * Filament's test helper: item keys are made predictable until the returned closure
     * (or `undoRepeaterFake()`) runs. Alfakit item keys are always the item's position
     * (`'0'`, `'1'` …), so tests can fill and assert repeaters as plain lists either way;
     * the fake only keeps Filament tests working unchanged.
     */
    public static function fake(): Closure
    {
        static::$isFaked = true;

        return static function (): void {
            static::undoRepeaterFake();
        };
    }

    public static function undoRepeaterFake(): void
    {
        static::$isFaked = false;
    }

    public static function isFaked(): bool
    {
        return static::$isFaked;
    }

    // ---------------------------------------------------------------- Item state

    /**
     * The index of the item `$key` (its position, as a string or int), or null.
     */
    public function getItemIndex(mixed $key): ?int
    {
        if (is_int($key)) {
            $index = $key;
        } elseif (is_string($key) && preg_match('/^\d+$/', $key) === 1) {
            $index = (int) $key;
        } else {
            return null;
        }

        return array_key_exists($index, $this->getItems()) ? $index : null;
    }

    /**
     * The item `$key` exactly as stored in the form state.
     *
     * @return array<string, mixed>
     */
    public function getRawItemState(int|string $key): array
    {
        $index = $this->getItemIndex($key);
        $item = $index === null ? null : $this->getItems()[$index];

        return is_array($item) ? $item : [];
    }

    /**
     * The item `$key` dehydrated through its schema, as it would be saved.
     *
     * @return array<string, mixed>
     */
    public function getItemState(int|string $key): array
    {
        $index = $this->getItemIndex($key);

        return $index === null ? [] : $this->dehydrateItem($index);
    }

    /**
     * Replace the items (a list) and run `afterStateUpdated()`.
     *
     * @param  array<int|string, mixed>  $items
     */
    public function updateItems(array $items): static
    {
        $old = $this->getState();

        $this->getContainer()->getRootSchema()->setStateAt($this->getStatePath(), array_values($items));
        $this->flushChildSchema();
        $this->callAfterStateUpdated($old);

        return $this;
    }

    public function canAddItems(?int $count = null): bool
    {
        $max = $this->evaluate($this->maxItems);

        return (bool) $this->evaluate($this->isAddable)
            && ($max === null || ($count ?? count($this->getItems())) < (int) $max);
    }

    public function canDeleteItems(?int $count = null): bool
    {
        $min = $this->evaluate($this->minItems);

        return (bool) $this->evaluate($this->isDeletable)
            && ($min === null || ($count ?? count($this->getItems())) > (int) $min);
    }

    /**
     * Append an item (`$state`, or a blank one) unless `addable()` / `maxItems()` forbid it.
     *
     * @param  array<string, mixed>|null  $state
     */
    public function addItem(?array $state = null): static
    {
        $items = $this->getItems();

        if (! $this->canAddItems(count($items))) {
            return $this;
        }

        $items[] = $state ?? $this->getTemplateState();

        return $this->updateItems($items);
    }

    public function deleteItem(mixed $key): static
    {
        $index = $this->getItemIndex($key);
        $items = $this->getItems();

        if ($index === null || ! $this->canDeleteItems(count($items))) {
            return $this;
        }

        unset($items[$index]);

        return $this->updateItems($items);
    }

    /**
     * Insert a copy of the item `$key` after it. A copied relationship item is a new item,
     * so it loses its key.
     */
    public function cloneItem(mixed $key): static
    {
        $index = $this->getItemIndex($key);
        $items = $this->getItems();

        if ($index === null || ! $this->evaluate($this->isCloneable) || ! $this->canCloneItem($index)) {
            return $this;
        }

        $copy = $items[$index];
        $relationship = $this->hasRelationship() ? $this->getRelationship() : null;

        if (is_array($copy) && $relationship instanceof BelongsToMany) {
            // Each related record can be attached once: the copy keeps the pivot data and
            // asks for another record.
            $copy[$this->getItemKeyName($relationship)] = null;
        } elseif (is_array($copy) && $relationship !== null) {
            unset($copy[$this->getItemKeyName($relationship)]);
        }

        array_splice($items, $index + 1, 0, [$copy]);

        return $this->updateItems($items);
    }

    protected function canCloneItem(int $index): bool
    {
        return $this->canAddItems();
    }

    /**
     * Move the item `$key` by `$offset` positions (`-1` up, `1` down).
     */
    public function moveItem(mixed $key, int $offset): static
    {
        $index = $this->getItemIndex($key);
        $items = $this->getItems();
        $target = $index === null ? null : $index + $offset;

        if ($index === null || ! $this->evaluate($this->isReorderable) || $target < 0 || $target >= count($items)) {
            return $this;
        }

        [$moved] = array_splice($items, $index, 1);
        array_splice($items, $target, 0, [$moved]);

        return $this->updateItems($items);
    }

    /**
     * Put the items in the order of `$keys` (every current item key, once).
     *
     * @param  array<mixed>  $keys
     */
    public function reorderItems(array $keys): static
    {
        $items = $this->getItems();
        $indexes = array_map(fn (mixed $key): ?int => $this->getItemIndex($key), array_values($keys));

        if (! $this->evaluate($this->isReorderable) || in_array(null, $indexes, true) || count(array_unique($indexes)) !== count($items)) {
            return $this;
        }

        return $this->updateItems(array_map(static fn (int $index): mixed => $items[$index], $indexes));
    }

    // ---------------------------------------------------------------- Sibling items

    /**
     * The repeater (or builder) item that holds `$component`: the repeater, the item index
     * (null inside the blank item template) and the component's state path relative to
     * the item.
     *
     * @return array{0: Repeater, 1: int|null, 2: string}|null
     */
    public static function findItemOf(Component $component): ?array
    {
        $path = $component->getStatePath();
        $container = $component->hasContainer() ? $component->getContainer() : null;

        while ($container !== null && $path !== null) {
            $parent = $container->getParentComponent();

            if ($parent === null) {
                return null;
            }

            if ($parent instanceof self) {
                $prefix = $parent->getStatePath().'.';

                if (! str_starts_with($path, $prefix)) {
                    return null;
                }

                $rest = substr($path, strlen($prefix));
                $position = strpos($rest, '.');

                if ($position === false) {
                    return null;
                }

                $index = substr($rest, 0, $position);

                return [$parent, ctype_digit($index) ? (int) $index : null, substr($rest, $position + 1)];
            }

            $container = $parent->hasContainer() ? $parent->getContainer() : null;
        }

        return null;
    }

    /**
     * The states at `$relativePath` of every item except `$index` (every item when null),
     * keyed by item index.
     *
     * @return array<int, mixed>
     */
    public function getSiblingItemStates(?int $index, string $relativePath): array
    {
        $states = [];

        foreach ($this->getItems() as $itemIndex => $item) {
            if ($itemIndex !== $index && is_array($item)) {
                $states[$itemIndex] = Arr::get($item, $relativePath);
            }
        }

        return $states;
    }

    /**
     * Set the state at `$relativePath` of the item `$index`.
     */
    public function setItemStateAt(int $index, string $relativePath, mixed $state): static
    {
        $this->getContainer()->getRootSchema()->setStateAt($this->getStatePath().'.'.$index.'.'.$relativePath, $state);

        return $this;
    }

    // ---------------------------------------------------------------- Items

    public function managesChildFieldState(): bool
    {
        return true;
    }

    public function isSimple(): bool
    {
        return $this->simpleField !== null;
    }

    public function hasRelationship(): bool
    {
        return $this->relationshipName !== null;
    }

    /**
     * @return HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>|null
     */
    public function getRelationship(): HasMany|MorphMany|BelongsToMany|null
    {
        $name = $this->evaluate($this->relationshipName);
        $record = $this->getRecord();

        if ($name === null || $record === null) {
            return null;
        }

        $relationship = $record->{$name}();

        return ($relationship instanceof HasMany || $relationship instanceof MorphMany || $relationship instanceof BelongsToMany) ? $relationship : null;
    }

    /**
     * The item state key identifying an existing item: the related key for HasMany and
     * MorphMany, the related pivot key (`product_id`) for BelongsToMany.
     *
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relationship
     */
    protected function getItemKeyName(HasMany|MorphMany|BelongsToMany $relationship): string
    {
        return $relationship instanceof BelongsToMany ? $relationship->getRelatedPivotKeyName() : $relationship->getRelated()->getKeyName();
    }

    /**
     * The records this repeater manages, keyed by item key, in display order:
     * `modifyQueryUsing`, `orderColumn()` and `modifyRecordsUsing` applied. For a
     * BelongsToMany the values are the pivot records.
     *
     * @return array<string, Model>
     */
    protected function getManagedRecords(): array
    {
        $relationship = $this->getRelationship();

        if ($relationship === null) {
            return [];
        }

        $orderColumn = $this->evaluate($this->orderColumn);
        $query = $relationship->getQuery();

        if ($this->modifyRelationshipQueryUsing !== null) {
            // Builders are modified in place; a returned builder replaces the query of
            // HasMany/MorphMany relationships (BelongsToMany needs its own to load pivots).
            $modified = $this->evaluate($this->modifyRelationshipQueryUsing, ['query' => $query], [Builder::class => $query]);

            if ($modified instanceof Builder && ! $relationship instanceof BelongsToMany) {
                $query = $modified;
            }
        }

        if ($relationship instanceof BelongsToMany) {
            $missing = array_values(array_diff($this->getPivotColumnNames($relationship), $relationship->getPivotColumns()));

            if ($missing !== []) {
                $relationship->withPivot($missing);
            }

            if ($orderColumn !== null) {
                $relationship->orderByPivot($orderColumn);
            }

            $records = $relationship->get();
        } else {
            if ($orderColumn !== null) {
                $query->orderBy($orderColumn);
            }

            $records = $query->get();
        }

        if ($this->modifyRelationshipRecordsUsing !== null) {
            $records = $this->evaluate($this->modifyRelationshipRecordsUsing, ['records' => $records], [Collection::class => $records]) ?? $records;
        }

        $keyName = $this->getItemKeyName($relationship);
        $managed = [];

        foreach ($records as $record) {
            if (! $record instanceof Model) {
                continue;
            }

            if ($relationship instanceof BelongsToMany) {
                $pivot = $record->getRelation($relationship->getPivotAccessor());

                if ($pivot instanceof Model) {
                    $managed[(string) $pivot->getAttribute($keyName)] = $pivot;
                }

                continue;
            }

            $managed[(string) $record->getAttribute($keyName)] = $record;
        }

        return $managed;
    }

    /**
     * Pivot columns a BelongsToMany repeater reads and writes: the item fields other than
     * the pivot keys, plus the order column.
     *
     * @param  BelongsToMany<Model, Model>  $relationship
     * @return array<int, string>
     */
    protected function getPivotColumnNames(BelongsToMany $relationship): array
    {
        $columns = [];

        foreach ($this->getItemFields(self::TEMPLATE_INDEX, withHidden: true) as $field) {
            $columns[] = explode('.', $field->getStatePath(isAbsolute: false))[0];
        }

        if (($orderColumn = $this->evaluate($this->orderColumn)) !== null) {
            $columns[] = $orderColumn;
        }

        return array_values(array_unique(array_diff($columns, [
            $relationship->getRelatedPivotKeyName(),
            $relationship->getForeignPivotKeyName(),
        ])));
    }

    /**
     * The shared child schema is bound to the related (or pivot) model too, so fields
     * serialised outside an item never look for relationships on the owner.
     */
    public function getChildSchema(?string $statePath = null): Schema
    {
        $schema = parent::getChildSchema($statePath);

        if ($statePath === null && $this->hasRelationship() && ($related = $this->getRelatedModel()) !== null) {
            $schema->model($related::class);
        }

        return $schema;
    }

    /**
     * The child schema for the item at `$index` (or the blank template). Cached per index.
     */
    public function getItemSchema(int|string $index): Schema
    {
        $key = (string) $index;

        if (isset($this->itemSchemas[$key])) {
            return $this->bindItemRecord($this->itemSchemas[$key], $index);
        }

        $schema = $this->getChildSchema($this->getStatePath().'.'.$index);

        // Relationship items are records of the related model: rules like `unique()` and
        // `exists()` use its table, and existing items expose their own `$record`.
        if (($related = $this->getRelatedModel()) !== null) {
            $schema->model($related::class);
        }

        return $this->itemSchemas[$key] = $this->bindItemRecord($schema, $index);
    }

    /**
     * Point a relationship item schema at the existing related record its state refers to.
     * Re-checked on every access because the browser's state may reorder items after the
     * schema was built; records set explicitly (when saving new items) are kept.
     */
    protected function bindItemRecord(Schema $schema, int|string $index): Schema
    {
        if (! $this->hasRelationship() || $index === self::TEMPLATE_INDEX || ($relationship = $this->getRelationship()) === null) {
            return $schema;
        }

        $itemKey = $this->getContainer()->getStateAt($this->getStatePath().'.'.$index.'.'.$this->getItemKeyName($relationship));
        $itemKey = is_scalar($itemKey) && $itemKey !== '' ? (string) $itemKey : null;
        $indexKey = (string) $index;

        if (array_key_exists($indexKey, $this->boundItemKeys) && $this->boundItemKeys[$indexKey] === $itemKey) {
            return $schema;
        }

        $this->boundItemKeys[$indexKey] = $itemKey;

        if ($itemKey === null) {
            return $schema->record(null);
        }

        $this->relatedRecords ??= $this->getManagedRecords();

        return $schema->record($this->relatedRecords[$itemKey] ?? null);
    }

    /**
     * An instance of the related model of a relationship repeater, also before the parent
     * record exists (create pages).
     */
    public function getRelatedModel(): ?Model
    {
        if (! $this->hasRelationship()) {
            return null;
        }

        if (($relationship = $this->getRelationship()) !== null) {
            return $this->getItemModel($relationship);
        }

        $name = $this->evaluate($this->relationshipName);
        $model = $this->getModelInstance();

        if (! is_string($name) || $model === null || ! method_exists($model, $name)) {
            return null;
        }

        $relationship = $model->{$name}();

        return ($relationship instanceof HasMany || $relationship instanceof MorphMany || $relationship instanceof BelongsToMany) ? $this->getItemModel($relationship) : null;
    }

    /**
     * The model of one item: the related model, or the pivot model of a BelongsToMany.
     *
     * @param  HasMany<Model, Model>|MorphMany<Model, Model>|BelongsToMany<Model, Model>  $relationship
     */
    protected function getItemModel(HasMany|MorphMany|BelongsToMany $relationship): Model
    {
        return $relationship instanceof BelongsToMany ? $relationship->newPivot() : $relationship->getRelated();
    }

    /**
     * A clone (this repeater inside a builder block or another repeater's item) builds its
     * own item schemas: the ones cached by the original were built for its path, so a new
     * block's repeater wrote its items to `content.items.N` instead of `content.B.data.items.N`.
     */
    public function __clone(): void
    {
        parent::__clone();

        $this->itemSchemas = [];
        $this->relatedRecords = null;
        $this->boundItemKeys = [];
    }

    public function flushChildSchema(): static
    {
        $this->itemSchemas = [];
        $this->relatedRecords = null;
        $this->boundItemKeys = [];

        return parent::flushChildSchema();
    }

    public function getNestedFieldByStatePath(string $path): ?Field
    {
        $relative = substr($path, strlen($this->getStatePath()) + 1);
        $index = strtok($relative, '.');

        if ($index === false || ! array_key_exists((int) $index, $this->getItems())) {
            return null;
        }

        return $this->getItemSchema((int) $index)->getFieldByStatePath($path);
    }

    /**
     * The fields owned directly by the item at `$index` (nested repeaters manage their own).
     *
     * @return array<Field>
     */
    protected function getItemFields(int|string $index, bool $withHidden = false): array
    {
        $schema = $this->getItemSchema($index);

        return array_values(array_filter(
            $schema->getFlatFields($withHidden),
            static fn (Field $field): bool => ! $field->isNestedInRepeater($schema),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getItems(): array
    {
        $state = $this->getState();

        return is_array($state) ? array_values($state) : [];
    }

    public function normalizeClientState(): void
    {
        foreach (array_keys($this->getItems()) as $index) {
            foreach ($this->getItemFields($index) as $field) {
                $field->normalizeClientState();
            }
        }
    }

    /**
     * Item fields that validate a transformed value (`trim()`, `stripCharacters()`).
     *
     * @return array<string, mixed>
     */
    public function getValidationStateByPath(): array
    {
        $state = parent::getValidationStateByPath();

        foreach (array_keys($this->getItems()) as $index) {
            foreach ($this->getItemFields($index) as $field) {
                $state = [...$state, ...$field->getValidationStateByPath()];
            }
        }

        return $state;
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return [];
    }

    // ---------------------------------------------------------------- Hydration

    public function hydrateState(bool $useDefaults): void
    {
        $this->itemSchemas = [];
        $this->relatedRecords = null;
        $this->boundItemKeys = [];

        $root = $this->getContainer()->getRootSchema();
        $path = $this->getStatePath();

        if ($this->hasRelationship() && ! $useDefaults) {
            $this->loadStateFromRelationships();
        } elseif ($useDefaults || ! Arr::has($root->getRawState(), $path)) {
            $defaultState = $this->hasDefault() ? $this->getDefaultState() : null;

            $root->setStateAt($path, $defaultState ?? array_fill(0, (int) $this->evaluate($this->defaultItems), []));
        }

        $items = $this->getItems();

        if ($this->isSimple()) {
            $items = array_map(fn (mixed $value): array => is_array($value) ? $value : [$this->simpleField->getName() => $value], $items);
        }

        $root->setStateAt($path, $items);

        foreach (array_keys($items) as $index) {
            foreach ($this->getItemFields($index, withHidden: true) as $field) {
                // Item values the field's own `default()` lists are kept; only missing
                // ones (new items, `defaultItems()`) take the item fields' defaults.
                $field->hydrateState(! Arr::has($root->getRawState(), $field->getStatePath()));
            }
        }

        $this->callAfterStateHydrated();
    }

    public function loadStateFromRelationships(): void
    {
        if ($this->getRelationship() === null) {
            return;
        }

        $records = $this->getManagedRecords();
        $this->relatedRecords = $records;

        $items = array_values(array_map(function (Model $related): array {
            $data = $related->attributesToArray();

            if ($this->mutateRelationshipDataBeforeFillUsing !== null) {
                $data = $this->evaluate($this->mutateRelationshipDataBeforeFillUsing, ['data' => $data, 'record' => $related], [Model::class => $related, $related::class => $related]) ?? $data;
            }

            return $data;
        }, $records));

        $this->getContainer()->getRootSchema()->setStateAt($this->getStatePath(), $items);
    }

    // ---------------------------------------------------------------- Dehydration

    public function dehydrateState(): mixed
    {
        $items = [];

        foreach (array_keys($this->getItems()) as $index) {
            $item = $this->dehydrateItem($index);

            $items[] = $this->isSimple() ? ($item[$this->simpleField->getName()] ?? null) : $item;
        }

        if ($this->dehydrateStateUsing !== null) {
            $items = $this->evaluate($this->dehydrateStateUsing, ['state' => $items]);
        }

        if ($this->mutateDehydratedStateUsing !== null) {
            $items = $this->evaluate($this->mutateDehydratedStateUsing, ['state' => $items]);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    protected function dehydrateItem(int|string $index): array
    {
        $item = [];

        foreach ($this->getItemFields($index) as $field) {
            if ($field->isDehydrated()) {
                $field->dehydrateStateInto($item, $field->getStatePath(isAbsolute: false));
            }
        }

        return $item;
    }

    public function isDehydrated(): bool
    {
        return ! $this->hasRelationship() && parent::isDehydrated();
    }

    // ---------------------------------------------------------------- Read-only items

    /**
     * Put back the server's values of read-only fields inside the submitted items.
     * Only existing relationship items can be matched to their server counterpart (by
     * key); plain items have no identity once the browser adds, deletes or reorders
     * them, so their read-only fields are left as submitted.
     */
    public function restoreReadOnlyItemState(mixed $serverItems): void
    {
        $relationship = $this->hasRelationship() ? $this->getRelationship() : null;

        if ($relationship === null || $this->isReadOnly()) {
            return;
        }

        $keyName = $this->getItemKeyName($relationship);
        $serverByKey = [];

        foreach (is_array($serverItems) ? $serverItems : [] as $item) {
            if (is_array($item) && isset($item[$keyName]) && is_scalar($item[$keyName])) {
                $serverByKey[(string) $item[$keyName]] = $item;
            }
        }

        $root = $this->getContainer()->getRootSchema();

        foreach ($this->getItems() as $index => $item) {
            $key = is_array($item) ? ($item[$keyName] ?? null) : null;
            $serverItem = is_scalar($key) ? ($serverByKey[(string) $key] ?? null) : null;

            if ($serverItem === null) {
                continue;
            }

            foreach ($this->getItemFields($index) as $field) {
                $relativePath = $field->getStatePath(isAbsolute: false);

                if ($field->isReadOnly()) {
                    $root->setStateAt($field->getStatePath(), Arr::get($serverItem, $relativePath));
                } elseif ($field instanceof self) {
                    $field->restoreReadOnlyItemState(Arr::get($serverItem, $relativePath));
                }
            }
        }
    }

    /**
     * Whether any current item holds a read-only field (directly or in a nested repeater).
     */
    public function hasReadOnlyItemFields(): bool
    {
        if (! $this->hasRelationship() || $this->isReadOnly()) {
            return false;
        }

        foreach (array_keys($this->getItems()) as $index) {
            foreach ($this->getItemFields($index) as $field) {
                if ($field->isReadOnly() || ($field instanceof self && $field->hasReadOnlyItemFields())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Create, update and delete (attach, update and detach, for a BelongsToMany) the
     * related records after the owner was saved, in item order.
     *
     * `mutateRelationshipDataBeforeCreateUsing()` / `...BeforeSaveUsing()` returning
     * null skip creating / saving that item; `afterCreate()`, `afterUpdate()` and
     * `afterDelete()` run for each item.
     */
    public function saveRelationships(): void
    {
        if (! $this->shouldSaveRelationships()) {
            return;
        }

        $relationship = $this->getRelationship();

        if ($relationship === null) {
            return;
        }

        if ($relationship instanceof BelongsToMany) {
            $this->savePivotItems($relationship);

            return;
        }

        $keyName = $relationship->getRelated()->getKeyName();
        $orderColumn = $this->evaluate($this->orderColumn);

        $existing = $this->getManagedRecords();
        $keptKeys = [];

        foreach (array_keys($this->getItems()) as $index) {
            $itemSchema = $this->getItemSchema($index);
            $data = $this->dehydrateItem($index);

            if ($orderColumn !== null) {
                $data[$orderColumn] = $index;
            }

            $itemKey = $this->getContainer()->getStateAt($this->getStatePath().'.'.$index.'.'.$keyName);
            $record = is_scalar($itemKey) ? ($existing[(string) $itemKey] ?? null) : null;

            if ($record !== null) {
                $keptKeys[] = (string) $itemKey;

                if ($this->mutateRelationshipDataBeforeSaveUsing !== null) {
                    $data = $this->evaluate($this->mutateRelationshipDataBeforeSaveUsing, ['data' => $data, 'record' => $record], [Model::class => $record, $record::class => $record]);
                }

                if (is_array($data)) {
                    $record->fill(Arr::except($data, [$keyName]))->save();
                    $this->callItemHook($this->afterUpdate, $data, $record);
                }
            } else {
                if ($this->mutateRelationshipDataBeforeCreateUsing !== null) {
                    $data = $this->evaluate($this->mutateRelationshipDataBeforeCreateUsing, ['data' => $data]);
                }

                if (! is_array($data)) {
                    continue;
                }

                // `make()` sets the foreign key (and morph type); new items of a tenant-owned
                // model belong to the current tenant.
                $record = TenantScope::associate($relationship->make(Arr::except($data, [$keyName])));
                $keptKeys[] = (string) $record->getKey();
                $this->callItemHook($this->afterCreate, $data, $record);
            }

            $itemSchema->record($record)->saveRelationships();
        }

        foreach (Arr::except($existing, $keptKeys) as $record) {
            $record->delete();
            $this->callItemHook($this->afterDelete, null, $record);
        }

        $this->relatedRecords = null;
        $this->boundItemKeys = [];
    }

    /**
     * Save the items of a BelongsToMany repeater: the related pivot key of each item picks
     * the related record, the other item fields are written to the pivot row.
     *
     * @param  BelongsToMany<Model, Model>  $relationship
     */
    protected function savePivotItems(BelongsToMany $relationship): void
    {
        $relatedKeyName = $relationship->getRelatedPivotKeyName();
        $pivotKeys = [$relatedKeyName, $relationship->getForeignPivotKeyName()];
        $orderColumn = $this->evaluate($this->orderColumn);

        $existing = $this->getManagedRecords();
        $keptKeys = [];

        foreach (array_keys($this->getItems()) as $index) {
            $data = $this->dehydrateItem($index);
            $relatedKey = $data[$relatedKeyName] ?? null;

            // Validation requires the key; an item without one (or repeating one) is skipped.
            if (! is_scalar($relatedKey) || $relatedKey === '' || in_array((string) $relatedKey, $keptKeys, true)) {
                continue;
            }

            if ($orderColumn !== null) {
                $data[$orderColumn] = $index;
            }

            $pivot = $existing[(string) $relatedKey] ?? null;

            if ($pivot !== null) {
                $keptKeys[] = (string) $relatedKey;

                if ($this->mutateRelationshipDataBeforeSaveUsing !== null) {
                    $data = $this->evaluate($this->mutateRelationshipDataBeforeSaveUsing, ['data' => $data, 'record' => $pivot], [Model::class => $pivot, $pivot::class => $pivot]);
                }

                if (is_array($data)) {
                    $attributes = Arr::except($data, $pivotKeys);

                    if ($attributes !== []) {
                        $relationship->updateExistingPivot($relatedKey, $attributes);
                        $pivot->forceFill($attributes);
                    }

                    $this->callItemHook($this->afterUpdate, $data, $pivot);
                }

                continue;
            }

            if ($this->mutateRelationshipDataBeforeCreateUsing !== null) {
                $data = $this->evaluate($this->mutateRelationshipDataBeforeCreateUsing, ['data' => $data]);
            }

            if (! is_array($data)) {
                continue;
            }

            $relationship->attach($relatedKey, Arr::except($data, $pivotKeys));
            $keptKeys[] = (string) $relatedKey;

            if ($this->afterCreate !== null && ($related = $relationship->getRelated()->newQuery()->where($relationship->getRelatedKeyName(), $relatedKey)->first()) !== null) {
                $this->callItemHook($this->afterCreate, $data, $related);
            }
        }

        foreach (Arr::except($existing, $keptKeys) as $relatedKey => $pivot) {
            $relationship->detach($relatedKey);
            $this->callItemHook($this->afterDelete, null, $pivot);
        }

        $this->relatedRecords = null;
        $this->boundItemKeys = [];
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    protected function callItemHook(?Closure $hook, ?array $data, Model $record): void
    {
        if ($hook === null) {
            return;
        }

        $this->evaluate($hook, ['data' => $data ?? [], 'record' => $record], [Model::class => $record, $record::class => $record]);
    }

    // ---------------------------------------------------------------- Validation

    protected function getTypeValidationRules(): array
    {
        $rules = ['array'];

        if (($min = $this->evaluate($this->minItems)) !== null) {
            $rules[] = "min:{$min}";
        }

        if (($max = $this->evaluate($this->maxItems)) !== null) {
            $rules[] = "max:{$max}";
        }

        if (! $this->allowsAddingItems() || ! $this->allowsDeletingItems() || ! $this->evaluate($this->isReorderable)) {
            // Implicit, so it also runs when the browser drops the repeater from the payload.
            $rules[] = new class(fn (): ?string => $this->getItemConstraintViolation()) implements ValidationRule
            {
                public bool $implicit = true;

                public function __construct(private readonly Closure $check) {}

                public function validate(string $attribute, mixed $value, Closure $fail): void
                {
                    if (($error = ($this->check)()) !== null) {
                        $fail(__("alfakit::forms.repeater.{$error}"));
                    }
                }
            };
        }

        return $rules;
    }

    /**
     * Compare the submitted items with the server-filled ones and report which disabled
     * operation (`cannot_add`, `cannot_delete`, `cannot_reorder`) the browser performed.
     * Relationship items are matched by key; plain items can only be counted.
     */
    protected function getItemConstraintViolation(): ?string
    {
        $server = $this->getContainer()->getServerStateAt($this->getStatePath());

        if (! is_array($server)) {
            return null;
        }

        $server = array_values($server);
        $client = $this->getItems();
        $addable = $this->allowsAddingItems();
        $deletable = $this->allowsDeletingItems();
        $reorderable = (bool) $this->evaluate($this->isReorderable);

        $relationship = $this->hasRelationship() ? $this->getRelationship() : null;
        $keyName = $relationship === null ? null : $this->getItemKeyName($relationship);

        if ($keyName === null) {
            if (! $addable && count($client) > count($server)) {
                return 'cannot_add';
            }

            return ! $deletable && count($client) < count($server) ? 'cannot_delete' : null;
        }

        $keyOf = static fn (mixed $item): ?string => is_array($item) && isset($item[$keyName]) && is_scalar($item[$keyName]) ? (string) $item[$keyName] : null;
        $serverKeys = array_values(array_filter(array_map($keyOf, $server), static fn (?string $key): bool => $key !== null));
        $clientKeys = [];
        $added = 0;

        foreach ($client as $item) {
            $key = $keyOf($item);

            if ($key === null || ! in_array($key, $serverKeys, true) || in_array($key, $clientKeys, true)) {
                $added++;
            } else {
                $clientKeys[] = $key;
            }
        }

        // Blank items the server rendered itself (default items on create) aren't additions.
        if (! $addable && $added > count($server) - count($serverKeys)) {
            return 'cannot_add';
        }

        if (! $deletable && (array_diff($serverKeys, $clientKeys) !== [] || count($client) < count($server))) {
            return 'cannot_delete';
        }

        if (! $reorderable && $clientKeys !== array_values(array_intersect($serverKeys, $clientKeys))) {
            return 'cannot_reorder';
        }

        return null;
    }

    public function getValidationRulesByPath(): array
    {
        $rules = parent::getValidationRulesByPath();

        // Validate each item with its concrete per-index schema so closures see item state.
        foreach (array_keys($this->getItems()) as $index) {
            foreach ($this->getItemFields($index) as $field) {
                foreach ($field->getValidationRulesByPath() as $path => $fieldRules) {
                    if ($fieldRules === []) {
                        continue;
                    }

                    $rules[$path] = $fieldRules;

                    // Laravel's `distinct` only compares values matched by a wildcard rule,
                    // so register it under `{repeater}.*.{field}` too: the errors still land
                    // on the concrete path of each duplicate item.
                    if ($path === $field->getStatePath() && ($distinct = $this->getDistinctRules($fieldRules)) !== []) {
                        $wildcard = $this->getStatePath().'.*.'.substr($path, strlen($this->getStatePath().'.'.$index.'.'));
                        $rules[$wildcard] = array_values(array_unique([...$rules[$wildcard] ?? [], ...$distinct]));
                    }
                }
            }
        }

        // A related record can be attached once: the related pivot key of a BelongsToMany
        // repeater is required and distinct across items. Wildcard rules go after the
        // concrete ones, which Laravel would otherwise let replace them.
        if ($this->hasRelationship() && ($relationship = $this->getRelationship()) instanceof BelongsToMany) {
            $wildcard = $this->getStatePath().'.*.'.$relationship->getRelatedPivotKeyName();
            $existing = $rules[$wildcard] ?? [];
            unset($rules[$wildcard]);
            $rules[$wildcard] = array_values(array_unique([...$existing, 'required', 'distinct'], SORT_REGULAR));
        }

        return $rules;
    }

    /**
     * The `distinct` rules (`distinct`, `distinct:strict`, `distinct:ignore_case`) among a
     * field's rules.
     *
     * @param  array<mixed>  $rules
     * @return array<int, string>
     */
    protected function getDistinctRules(array $rules): array
    {
        return array_values(array_filter($rules, static fn (mixed $rule): bool => is_string($rule) && ($rule === 'distinct' || str_starts_with($rule, 'distinct:'))));
    }

    public function getValidationAttributesByPath(): array
    {
        $attributes = parent::getValidationAttributesByPath();

        foreach (array_keys($this->getItems()) as $index) {
            foreach ($this->getItemFields($index) as $field) {
                $attributes = [...$attributes, ...$field->getValidationAttributesByPath()];
            }
        }

        return $attributes;
    }

    public function getValidationMessagesByPath(): array
    {
        $messages = parent::getValidationMessagesByPath();

        foreach (array_keys($this->getItems()) as $index) {
            foreach ($this->getItemFields($index) as $field) {
                $messages = [...$messages, ...$field->getValidationMessagesByPath()];
            }
        }

        return $messages;
    }

    // ---------------------------------------------------------------- Serialisation

    protected function getItemLabel(int $index): ?string
    {
        if ($this->itemLabelUsing !== null) {
            return $this->evaluate($this->itemLabelUsing, [
                'state' => $this->getItems()[$index] ?? [],
                'index' => $index,
            ]);
        }

        return $this->evaluate($this->itemLabel);
    }

    public function toSchema(): array
    {
        $schema = parent::toSchema();
        unset($schema['schema']);

        return $schema;
    }

    /**
     * Item fields lay out in two columns from `lg` unless `columns()` says otherwise.
     *
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1, 'lg' => 2];
    }

    /**
     * `deferLoading()`: the page's first response sends the field without its items'
     * schemas (a skeleton in the browser); the renderer loads it in full from the
     * deferred-schema endpoint once it scrolls into view, or when an item has an error.
     *
     * @return array<string, mixed>
     */
    protected function getDeferredPlaceholderProps(): array
    {
        return [
            'deferred' => true,
            'deferredPaths' => [$this->getStatePath()],
            'items' => [],
            'template' => [],
            'templateState' => [],
            ...$this->getItemControlsSchema(),
        ];
    }

    protected function getSchemaProps(): array
    {
        if ($this->shouldRenderDeferredPlaceholder()) {
            return $this->getDeferredPlaceholderProps();
        }

        $grid = $this->evaluate($this->grid);

        return [
            'items' => collect(array_keys($this->getItems()))->map(fn (int $index): array => [
                'key' => (string) $index,
                'label' => $this->getItemLabel($index),
                'schema' => $this->getItemSchema($index)->toSchema(),
                ...$this->getItemActionsProps((string) $index),
            ])->values()->all(),
            'template' => $this->getItemSchema(self::TEMPLATE_INDEX)->toSchema(),
            'templateState' => $this->getTemplateState(),
            'simple' => $this->isSimple() ?: null,
            ...$this->getItemControlsSchema(),
            'grid' => $grid === null ? null : (is_array($grid) ? $grid : ['default' => 1, 'lg' => $grid]),
            'table' => $this->isSimple() ? null : $this->getTableColumnsSchema(),
            'compact' => ! $this->isSimple() && $this->isTable() && $this->isCompact() ? true : null,
            'virtualize' => $this->getVirtualizeSchema(),
        ];
    }

    /**
     * Props shared by Repeater and Builder: limits, add/delete/clone/reorder/collapse
     * switches, the built-in item actions and their layout.
     *
     * @return array<string, mixed>
     */
    protected function getItemControlsSchema(): array
    {
        $min = $this->evaluate($this->minItems);
        $max = $this->evaluate($this->maxItems);

        return [
            // Omitted when unset: renderers must never read a missing limit as 0.
            ...(is_int($min) ? ['minItems' => $min] : []),
            ...(is_int($max) ? ['maxItems' => $max] : []),
            'addable' => $this->allowsAddingItems(),
            'deletable' => $this->allowsDeletingItems(),
            'reorderable' => (bool) $this->evaluate($this->isReorderable),
            'reorderableWithButtons' => $this->isReorderableWithButtons() ? null : false,
            'reorderableWithDragAndDrop' => $this->isReorderableWithDragAndDrop() ? null : false,
            'cloneable' => $this->evaluate($this->isCloneable) ?: null,
            'collapsible' => $this->evaluate($this->isCollapsible) ?: null,
            'collapsed' => $this->evaluate($this->isCollapsed) ?: null,
            'addActionLabel' => $this->getDefaultAddActionLabel(),
            'addActionAlignment' => $this->getAddActionAlignment(),
            'itemNumbers' => $this->hasItemNumbers() ?: null,
            'itemActions' => $this->isReadOnly() ? null : $this->getFieldItemActionsSchema(),
        ];
    }

    /**
     * `addable()`, and the add action not hidden by `addAction()`.
     */
    public function allowsAddingItems(): bool
    {
        return (bool) $this->evaluate($this->isAddable)
            && (! isset($this->itemActionModifiers['add']) || $this->isItemActionShown('add'));
    }

    /**
     * `deletable()`, and the delete action not hidden by `deleteAction()`.
     */
    public function allowsDeletingItems(): bool
    {
        return (bool) $this->evaluate($this->isDeletable)
            && (! isset($this->itemActionModifiers['delete']) || $this->isItemActionShown('delete'));
    }

    /**
     * Default state for a freshly added item.
     *
     * @return array<string, mixed>
     */
    protected function getTemplateState(): array
    {
        $state = [];

        foreach ($this->getItemFields(self::TEMPLATE_INDEX, withHidden: true) as $field) {
            Arr::set($state, $field->getStatePath(isAbsolute: false), $field->hasDefault() ? $field->getDefaultState() : $field->getDefaultStateForTemplate());
        }

        return $state;
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
