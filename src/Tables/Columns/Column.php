<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasExtraAttributes;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\VerticalAlignment;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\RecordState;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Enums\PaginationMode;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use ReflectionClass;
use stdClass;

/**
 * Base class for table columns. Columns are configured once and then asked to produce a
 * cell for each record via `toCell()`.
 */
abstract class Column
{
    use \AlfaSix\Alfakit\Schemas\Components\Concerns\HasColumnSpan;
    use Concerns\HasRelationshipAggregates;
    use Concerns\HasSummarizers;
    use Configurable;
    use EvaluatesClosures;
    use HasExtraAttributes;
    use Macroable;

    /**
     * The schema `type`. Set it on a custom column to render it with a frontend component
     * registered as `column:<type>`; otherwise the nearest built-in column type is used.
     */
    protected ?string $type = null;

    protected string $name;

    protected string|Closure|null $label = null;

    protected ?Table $table = null;

    /** The current record: a model, or an array row of a table with custom `records()`. */
    protected Model|array|null $record = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    /** Sorting */
    protected bool|Closure $isSortable = false;

    /** @var array<string>|null */
    protected ?array $sortColumns = null;

    protected ?Closure $sortQuery = null;

    /** Searching */
    protected bool|Closure $isSearchable = false;

    protected bool $isGloballySearchable = true;

    protected bool $isIndividuallySearchable = false;

    /** @var array<string>|null */
    protected ?array $searchColumns = null;

    protected ?Closure $searchQuery = null;

    protected bool $isSearchForcedCaseInsensitive = false;

    protected bool|Closure|null $shouldSplitIndividualSearchTerms = null;

    /** Toggling */
    protected bool|Closure $isToggleable = false;

    protected bool|Closure $isToggledHiddenByDefault = false;

    protected Alignment|Closure|null $alignment = null;

    protected VerticalAlignment|Closure|null $verticalAlignment = null;

    protected string|Closure|null $width = null;

    protected bool|Closure $canGrow = true;

    protected bool|Closure $isHeaderWrapped = false;

    /** Responsive visibility (`sm`, `md`, `lg`, `xl`, `2xl`). */
    protected string|Closure|null $visibleFrom = null;

    protected string|Closure|null $hiddenFrom = null;

    protected ?Closure $getStateUsing = null;

    protected mixed $defaultState = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected Action|string|Closure|null $action = null;

    protected string|Closure|null $tooltip = null;

    protected string|Closure|null $headerTooltip = null;

    protected bool|Closure $isClickDisabled = false;

    /** The Blade-style loop variable of the row being serialised (`$rowLoop`). */
    protected ?stdClass $rowLoop = null;

    /** Whether `toCell()` is running, so the cell's state is resolved once. */
    protected bool $isSerializingCell = false;

    /** @var array{0: mixed}|null The state of the cell being serialised. */
    protected ?array $cellState = null;

    /**
     * Values that are the same for every row (the panel's timezone, …), remembered while
     * the table serialises its rows (`serializingRows()`).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $rowPassCache = null;

    /** @var array<array<string, mixed>|Closure> */
    protected array $extraCellAttributes = [];

    /** @var array<array<string, mixed>|Closure> */
    protected array $extraHeaderAttributes = [];

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
        return 'column';
    }

    public function getType(): string
    {
        if ($this->type !== null) {
            return $this->type;
        }

        // A subclass of a built-in column (`StatusColumn extends IconColumn`) keeps the
        // built-in renderer; a direct `Column` subclass is a custom type named after itself.
        for ($class = static::class; $class !== false && $class !== self::class; $class = get_parent_class($class)) {
            if (str_starts_with($class, __NAMESPACE__.'\\') && ! (new ReflectionClass($class))->isAnonymous()) {
                return class_basename($class);
            }
        }

        return class_basename(static::class);
    }

    // ---------------------------------------------------------------- Identity

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

    /**
     * @param  Model|array<mixed>|null  $record
     */
    public function record(Model|array|null $record): static
    {
        $this->record = $record;
        $this->cellState = null;

        return $this;
    }

    /**
     * @return Model|array<mixed>|null
     */
    public function getRecord(): Model|array|null
    {
        return $this->record;
    }

    // ---------------------------------------------------------------- Visibility

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->isHidden) || ! $this->evaluate($this->isVisible) || $this->isHiddenByOwner();
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

    public function isVisible(): bool
    {
        return ! $this->isHidden();
    }

    public function toggleable(bool|Closure $condition = true, bool|Closure $isToggledHiddenByDefault = false): static
    {
        $this->isToggleable = $condition;
        $this->isToggledHiddenByDefault = $isToggledHiddenByDefault;

        return $this;
    }

    public function isToggleable(): bool
    {
        return (bool) $this->evaluate($this->isToggleable);
    }

    public function isToggledHiddenByDefault(): bool
    {
        return (bool) $this->evaluate($this->isToggledHiddenByDefault);
    }

    /**
     * Show the column only from this breakpoint up (`sm`, `md`, `lg`, `xl`, `2xl`). The
     * column stays part of the table (searchable, sortable, in the column manager); the
     * renderer hides its header and cells below the breakpoint.
     */
    public function visibleFrom(string|Closure|null $breakpoint): static
    {
        $this->visibleFrom = $breakpoint;

        return $this;
    }

    /**
     * Hide the column from this breakpoint up.
     */
    public function hiddenFrom(string|Closure|null $breakpoint): static
    {
        $this->hiddenFrom = $breakpoint;

        return $this;
    }

    public function getVisibleFrom(): ?string
    {
        return self::breakpoint($this->evaluate($this->visibleFrom));
    }

    public function getHiddenFrom(): ?string
    {
        return self::breakpoint($this->evaluate($this->hiddenFrom));
    }

    protected static function breakpoint(mixed $breakpoint): ?string
    {
        return in_array($breakpoint, ['sm', 'md', 'lg', 'xl', '2xl'], true) ? $breakpoint : null;
    }

    // ---------------------------------------------------------------- Sorting

    /**
     * @param  bool|array<string>|Closure  $condition  Column names to sort by, or a closure `fn (Builder $query, string $direction)`.
     */
    public function sortable(bool|array|Closure $condition = true, ?Closure $query = null): static
    {
        if (is_array($condition)) {
            $this->sortColumns = $condition;
            $condition = true;
        }

        $this->isSortable = $condition;
        $this->sortQuery = $query ?? ($condition instanceof Closure ? null : null);

        return $this;
    }

    public function isSortable(): bool
    {
        return (bool) $this->evaluate($this->isSortable);
    }

    public function applySort(Builder $query, string $direction): Builder
    {
        if ($this->sortQuery !== null) {
            $this->evaluate($this->sortQuery, ['query' => $query, 'direction' => $direction], [Builder::class => $query]);

            return $query;
        }

        foreach ($this->sortColumns ?? [$this->name] as $column) {
            if ($column === $this->name && $this->hasRelationshipAggregate()) {
                // `comments_count` is a select alias from `withCount()`, not a table column.
                $query->orderBy($column, $direction);
            } elseif ($this->isRelationshipPath($column)) {
                $this->applyRelationshipSort($query, $column, $direction);
            } else {
                $query->orderBy($query->qualifyColumn($column), $direction);
            }
        }

        return $query;
    }

    protected function applyRelationshipSort(Builder $query, string $path, string $direction): void
    {
        $model = $query->getModel();
        $subQuery = $this->buildRelationshipSortQuery($model, $model->getTable(), explode('.', Str::beforeLast($path, '.')), Str::afterLast($path, '.'), [$model->getTable()]);

        if ($subQuery === null) {
            return;
        }

        if ($this->table?->getPaginationMode() === PaginationMode::Cursor) {
            // A cursor stores the sorted values by column name, so the sub-select is selected
            // under an alias (`(select …) as alfakit_sort_author_name`) and the order names
            // the alias; Laravel then compares the cursor against the sub-select itself.
            $alias = 'alfakit_sort_'.str_replace('.', '_', $path);

            if (($query->getQuery()->columns ?? []) === []) {
                $query->select($query->qualifyColumn('*'));
            }

            $query->selectSub($subQuery, $alias)->orderBy($alias, $direction);

            return;
        }

        $query->orderBy($subQuery, $direction);
    }

    /**
     * A correlated sub-select returning the attribute at the end of a (possibly nested)
     * single-record relationship path: `author.name` becomes
     * `(select name from users where users.id = posts.author_id limit 1)`, and every further
     * segment nests one more sub-select. Tables already used further up the chain (self
     * relations) get an alias so the correlation stays unambiguous.
     *
     * @param  array<string>  $segments
     * @param  array<string>  $usedTables
     * @return Builder<Model>|null
     */
    protected function buildRelationshipSortQuery(Model $model, string $parentTable, array $segments, string $attribute, array $usedTables): ?Builder
    {
        $segment = array_shift($segments);

        if ($segment === null || ! method_exists($model, $segment)) {
            return null;
        }

        $relationship = $model->{$segment}();

        if (! $relationship instanceof BelongsTo && ! $relationship instanceof HasOne && ! $relationship instanceof MorphOne) {
            return null; // Many-relationships cannot be sorted on a single value.
        }

        $related = $relationship->getRelated();
        $table = $related->getTable();

        if (in_array($table, $usedTables, true)) {
            $alias = 'alfakit_sort_'.count($usedTables);
            $subQuery = $related->newQueryWithoutScopes()->from("{$table} as {$alias}");

            if (method_exists($related, 'getDeletedAtColumn')) {
                $subQuery->whereNull("{$alias}.".$related->getDeletedAtColumn());
            }
        } else {
            $alias = $table;
            $subQuery = $related->newQuery();
        }

        if ($relationship instanceof BelongsTo) {
            $subQuery->whereColumn("{$alias}.".$relationship->getOwnerKeyName(), "{$parentTable}.".$relationship->getForeignKeyName());
        } else {
            $subQuery->whereColumn("{$alias}.".$relationship->getForeignKeyName(), "{$parentTable}.".$relationship->getLocalKeyName());

            if ($relationship instanceof MorphOne) {
                $subQuery->getQuery()->where("{$alias}.".Str::afterLast($relationship->getMorphType(), '.'), $relationship->getMorphClass());
            }
        }

        if ($segments === []) {
            $subQuery->select("{$alias}.{$attribute}");
        } else {
            $nested = $this->buildRelationshipSortQuery($related, $alias, $segments, $attribute, [...$usedTables, $alias]);

            if ($nested === null) {
                return null;
            }

            $subQuery->selectSub($nested, 'alfakit_sort_value');
        }

        return $subQuery->limit(1);
    }

    // ---------------------------------------------------------------- Searching

    /**
     * @param  bool|array<string>|Closure  $condition  Column names to search, or a closure `fn (Builder $query, string $search)`.
     */
    public function searchable(bool|array|Closure $condition = true, ?Closure $query = null, bool $isIndividual = false, bool $isGlobal = true): static
    {
        if (is_array($condition)) {
            $this->searchColumns = $condition;
            $condition = true;
        }

        $this->isSearchable = $condition;
        $this->searchQuery = $query;
        $this->isIndividuallySearchable = $isIndividual;
        $this->isGloballySearchable = $isGlobal;

        return $this;
    }

    /**
     * Split this column's individual search into words (each must match), overriding the
     * table's `splitIndividualSearchTerms()`.
     */
    public function splitIndividualSearchTerms(bool|Closure|null $condition = true): static
    {
        $this->shouldSplitIndividualSearchTerms = $condition;

        return $this;
    }

    public function shouldSplitIndividualSearchTerms(): ?bool
    {
        $split = $this->evaluate($this->shouldSplitIndividualSearchTerms);

        return $split === null ? null : (bool) $split;
    }

    public function forceSearchCaseInsensitive(bool $condition = true): static
    {
        $this->isSearchForcedCaseInsensitive = $condition;

        return $this;
    }

    public function isSearchable(): bool
    {
        return (bool) $this->evaluate($this->isSearchable);
    }

    public function isGloballySearchable(): bool
    {
        return $this->isSearchable() && $this->isGloballySearchable;
    }

    public function isIndividuallySearchable(): bool
    {
        return $this->isSearchable() && $this->isIndividuallySearchable;
    }

    /**
     * Add `orWhere` constraints for the search term. Call inside a `where(fn () => …)` group.
     */
    public function applySearch(Builder $query, string $search): Builder
    {
        if ($this->searchQuery !== null) {
            $this->evaluate($this->searchQuery, ['query' => $query, 'search' => $search], [Builder::class => $query]);

            return $query;
        }

        foreach ($this->searchColumns ?? [$this->name] as $column) {
            if ($column === $this->name && $this->hasRelationshipAggregate()) {
                // `comments_count` is a select alias: search the aggregate sub-select instead.
                $this->applyAggregateSearch($query, $search, true);
            } elseif ($this->isRelationshipPath($column)) {
                $relationship = Str::beforeLast($column, '.');
                $attribute = Str::afterLast($column, '.');

                $query->orWhereHas($relationship, fn (Builder $q) => $this->applyLike($q, $q->qualifyColumn($attribute), $search, false));
            } else {
                $this->applyLike($query, $query->qualifyColumn($column), $search, true);
            }
        }

        return $query;
    }

    /**
     * `(select count(*) …) like ?` for an aggregate column (`counts()`, `avg()`, …).
     */
    protected function applyAggregateSearch(Builder $query, string $search, bool $or): void
    {
        $subQuery = $this->getRelationshipAggregateQuery($query);

        if ($subQuery === null) {
            return;
        }

        $driver = $query->getConnection()->getDriverName();
        $sql = "({$subQuery->toSql()})";

        // PostgreSQL won't `like` a number; MySQL and SQLite coerce it.
        if ($driver === 'pgsql') {
            $sql = "cast({$sql} as text)";
        }

        $operator = $driver === 'pgsql' || $this->isSearchForcedCaseInsensitive ? 'ilike' : 'like';

        if ($operator === 'ilike' && $driver !== 'pgsql') {
            $sql = "lower({$sql})";
            $search = mb_strtolower($search);
            $operator = 'like';
        }

        $method = $or ? 'orWhereRaw' : 'whereRaw';
        $query->{$method}("{$sql} {$operator} ?", [...$subQuery->getBindings(), "%{$search}%"]);
    }

    protected function applyLike(Builder $query, string $column, string $search, bool $or): void
    {
        $driver = $query->getConnection()->getDriverName();
        $operator = $driver === 'pgsql' || $this->isSearchForcedCaseInsensitive ? 'ilike' : 'like';

        if ($operator === 'ilike' && $driver !== 'pgsql') {
            $column = "lower({$column})";
            $search = mb_strtolower($search);
            $operator = 'like';
            $method = $or ? 'orWhereRaw' : 'whereRaw';
            $query->{$method}("{$column} {$operator} ?", ["%{$search}%"]);

            return;
        }

        $method = $or ? 'orWhere' : 'where';
        $query->{$method}($column, $operator, "%{$search}%");
    }

    // ---------------------------------------------------------------- Layout

    public function alignment(Alignment|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(): static
    {
        return $this->alignment(Alignment::Start);
    }

    public function alignCenter(): static
    {
        return $this->alignment(Alignment::Center);
    }

    public function alignEnd(): static
    {
        return $this->alignment(Alignment::End);
    }

    public function verticalAlignment(VerticalAlignment|Closure|null $alignment): static
    {
        $this->verticalAlignment = $alignment;

        return $this;
    }

    public function verticallyAlignStart(bool|Closure $condition = true): static
    {
        return $this->verticalAlignment(fn (): ?VerticalAlignment => $this->evaluate($condition) ? VerticalAlignment::Start : null);
    }

    public function verticallyAlignCenter(bool|Closure $condition = true): static
    {
        return $this->verticalAlignment(fn (): ?VerticalAlignment => $this->evaluate($condition) ? VerticalAlignment::Center : null);
    }

    public function verticallyAlignEnd(bool|Closure $condition = true): static
    {
        return $this->verticalAlignment(fn (): ?VerticalAlignment => $this->evaluate($condition) ? VerticalAlignment::End : null);
    }

    public function getVerticalAlignment(): ?VerticalAlignment
    {
        $alignment = $this->evaluate($this->verticalAlignment);

        return $alignment instanceof VerticalAlignment ? $alignment : (is_string($alignment) ? VerticalAlignment::tryFrom($alignment) : null);
    }

    public function width(string|Closure|null $width): static
    {
        $this->width = $width;

        return $this;
    }

    public function grow(bool|Closure $condition = true): static
    {
        $this->canGrow = $condition;

        return $this;
    }

    public function wrapHeader(bool|Closure $condition = true): static
    {
        $this->isHeaderWrapped = $condition;

        return $this;
    }

    /**
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraCellAttributes(array|Closure $attributes, bool $merge = false): static
    {
        $this->extraCellAttributes = $merge ? [...$this->extraCellAttributes, $attributes] : [$attributes];

        return $this;
    }

    /**
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraHeaderAttributes(array|Closure $attributes, bool $merge = false): static
    {
        $this->extraHeaderAttributes = $merge ? [...$this->extraHeaderAttributes, $attributes] : [$attributes];

        return $this;
    }

    // ---------------------------------------------------------------- Interaction

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function openUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenUrlInNewTab = $condition;

        return $this;
    }

    /**
     * The link target, or null when the URL uses a scheme other than http(s), mailto,
     * tel or a relative path (`javascript:` and `data:` URLs are dropped).
     */
    public function getUrl(): ?string
    {
        return \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url));
    }

    /**
     * Run a table action (by name or instance) when the cell is clicked.
     */
    public function action(Action|string|Closure|null $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getActionName(): ?string
    {
        $action = $this->evaluate($this->action);

        return $action instanceof Action ? $action->getName() : $action;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    /**
     * A tooltip on the column header (`fn (Table $table) => …` is evaluated without a record).
     */
    public function headerTooltip(string|Closure|null $tooltip): static
    {
        $this->headerTooltip = $tooltip;

        return $this;
    }

    public function getHeaderTooltip(): ?string
    {
        $tooltip = $this->evaluate($this->headerTooltip);

        return $tooltip === null || $tooltip === '' ? null : (string) $tooltip;
    }

    /**
     * Clicking the cell does nothing: neither the row's URL / record action nor the
     * column's own `url()` / `action()` run from it.
     */
    public function disabledClick(bool|Closure $condition = true): static
    {
        $this->isClickDisabled = $condition;

        return $this;
    }

    public function isClickDisabled(): bool
    {
        return (bool) $this->evaluate($this->isClickDisabled);
    }

    /**
     * Called by the table around serialising its rows: values that cannot change from one
     * row to the next are resolved once (`rememberForRows()`).
     */
    public function serializingRows(bool $condition = true): static
    {
        $this->rowPassCache = $condition ? [] : null;

        return $this;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    protected function rememberForRows(string $key, Closure $resolve): mixed
    {
        if ($this->rowPassCache === null) {
            return $resolve();
        }

        if (! array_key_exists($key, $this->rowPassCache)) {
            $this->rowPassCache[$key] = $resolve();
        }

        return $this->rowPassCache[$key];
    }

    public function rowLoop(?stdClass $loop): static
    {
        $this->rowLoop = $loop;

        return $this;
    }

    public function getRowLoop(): ?stdClass
    {
        return $this->rowLoop;
    }

    // ---------------------------------------------------------------- State

    public function getStateUsing(?Closure $callback): static
    {
        $this->getStateUsing = $callback;

        return $this;
    }

    public function state(mixed $state): static
    {
        return $this->getStateUsing(fn (): mixed => $this->evaluate($state));
    }

    public function default(mixed $state): static
    {
        $this->defaultState = $state;

        return $this;
    }

    public function getState(): mixed
    {
        if ($this->cellState !== null) {
            return $this->cellState[0];
        }

        $state = $this->resolveState();

        if ($this->isSerializingCell) {
            $this->cellState = [$state];
        }

        return $state;
    }

    protected function resolveState(): mixed
    {
        if ($this->getStateUsing !== null) {
            $state = $this->evaluate($this->getStateUsing);
        } else {
            $state = $this->record !== null ? RecordState::get($this->record, $this->name) : null;

            if ($state === null) {
                $state = $this->getPivotState();
            }
        }

        if ($state === null || $state === '' || $state === []) {
            $state = $this->evaluate($this->defaultState) ?? $state;
        }

        return $state;
    }

    /**
     * A plain column name (`role`) that the record lacks but its loaded pivot has: rows of
     * a BelongsToMany relation manager read `withPivot()` columns by name, as in Filament.
     */
    protected function getPivotState(): mixed
    {
        if (! $this->record instanceof Model || str_contains($this->name, '.')) {
            return null;
        }

        foreach ($this->record->getRelations() as $relation) {
            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\Pivot && array_key_exists($this->name, $relation->getAttributes())) {
                return $relation->getAttribute($this->name);
            }
        }

        return null;
    }

    /**
     * Relationship names (dotted) this column reads, for eager loading.
     *
     * @return array<string>
     */
    public function getRelationshipsToEagerLoad(): array
    {
        $paths = [];

        foreach ([$this->name, ...($this->sortColumns ?? []), ...($this->searchColumns ?? [])] as $path) {
            if ($this->isRelationshipPath($path)) {
                $paths[] = Str::beforeLast($path, '.');
            }
        }

        return array_values(array_unique($paths));
    }

    protected function isRelationshipPath(string $path): bool
    {
        if (! str_contains($path, '.')) {
            return false;
        }

        $model = $this->table?->getModelInstance();

        if ($model === null) {
            return true;
        }

        return $this->resolveRelationship($model, Str::beforeLast($path, '.')) !== null;
    }

    protected function resolveRelationship(Model $model, string $path): ?Relation
    {
        $relationship = null;

        foreach (explode('.', $path) as $segment) {
            if (! method_exists($model, $segment)) {
                return null;
            }

            $candidate = $model->{$segment}();

            if (! $candidate instanceof Relation) {
                return null;
            }

            $relationship = $candidate;
            $model = $candidate->getRelated();
        }

        return $relationship;
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * Static column definition for the table header.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return Serializable::compact([
            'type' => $this->getType(),
            'name' => $this->getName(),
            'label' => $this->getLabel(),
            'sortable' => $this->isSortable() ?: null,
            'searchable' => $this->isIndividuallySearchable() ?: null,
            'toggleable' => $this->isToggleable() ?: null,
            'toggledHiddenByDefault' => $this->isToggledHiddenByDefault() ?: null,
            'alignment' => $this->evaluate($this->alignment)?->value,
            'verticalAlignment' => $this->getVerticalAlignment()?->value,
            'headerTooltip' => $this->getHeaderTooltip(),
            'width' => $this->evaluate($this->width),
            'grow' => $this->evaluate($this->canGrow) ? null : false,
            'wrapHeader' => $this->evaluate($this->isHeaderWrapped) ?: null,
            'visibleFrom' => $this->getVisibleFrom(),
            'hiddenFrom' => $this->getHiddenFrom(),
            'extraHeaderAttributes' => $this->resolveAttributeStack($this->extraHeaderAttributes) ?: null,
            ...$this->getSchemaProps(),
        ]);
    }

    /**
     * Record-specific cell data.
     *
     * @param  Model|array<mixed>  $record
     * @return array<string, mixed>
     */
    public function toCell(Model|array $record): array
    {
        $this->record($record);
        // The state is read by the formatter, the cell props and every `$state` closure:
        // resolve it once per cell.
        $this->isSerializingCell = true;
        $this->cellState = null;

        try {
            return $this->serializeCell();
        } finally {
            $this->isSerializingCell = false;
            $this->cellState = null;
            $this->record(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeCell(): array
    {
        $disabledClick = $this->isClickDisabled();

        return Serializable::compact([
            'state' => $this->getFormattedState(),
            'url' => $disabledClick ? null : $this->getUrl(),
            'openUrlInNewTab' => ! $disabledClick && $this->shouldOpenUrlInNewTab() ?: null,
            'action' => $disabledClick ? null : $this->getActionName(),
            'disabledClick' => $disabledClick ?: null,
            'tooltip' => $this->evaluate($this->tooltip),
            'hidden' => $this->isHidden() ?: null,
            'extraAttributes' => $this->resolveAttributeStack($this->extraCellAttributes) ?: null,
            ...$this->getCellProps(),
        ]);
    }

    public function shouldOpenUrlInNewTab(): bool
    {
        return (bool) $this->evaluate($this->shouldOpenUrlInNewTab);
    }

    /**
     * The value shown in the cell. Override for type-specific formatting.
     */
    protected function getFormattedState(): mixed
    {
        return Serializable::value($this->getState());
    }

    /**
     * Column-type specific static props.
     *
     * @return array<string, mixed>
     */
    protected function getSchemaProps(): array
    {
        return [];
    }

    /**
     * Column-type specific per-record props.
     *
     * @return array<string, mixed>
     */
    protected function getCellProps(): array
    {
        return [];
    }

    /**
     * @param  array<array<string, mixed>|Closure>  $stack
     * @return array<string, mixed>
     */
    protected function resolveAttributeStack(array $stack): array
    {
        $attributes = [];

        foreach ($stack as $item) {
            $attributes = [...$attributes, ...($this->evaluate($item) ?? [])];
        }

        return $attributes;
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'record' => [$this->record],
            'state' => [$this->getState()],
            'table' => [$this->table],
            'rowLoop' => [$this->rowLoop],
            'index' => [$this->rowLoop === null ? null : $this->rowLoop->index],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return match (true) {
            $parameterType === Model::class && ! is_array($this->record) => [$this->record],
            $this->record instanceof $parameterType => [$this->record],
            $parameterType === Table::class => [$this->table],
            default => [],
        };
    }
}
