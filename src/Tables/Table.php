<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\BulkActionGroup;
use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Schemas\Components\Component as SchemaComponent;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\Concerns\CanBeVirtualized;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\ModelLabel;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Enums\FiltersLayout;
use AlfaSix\Alfakit\Tables\Enums\RecordActionsPosition;
use AlfaSix\Alfakit\Tables\Filters\BaseFilter;
use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Macroable;
use ReflectionFunction;
use ReflectionNamedType;
use stdClass;

/**
 * A data table: Eloquent query + columns + filters + actions, driven by a TableState and
 * serialised for the renderer.
 */
class Table implements HasResource
{
    use CanBeVirtualized;
    use Concerns\CanDeferFilters;
    use Concerns\CanDeferTotals;
    use Concerns\CanReorderColumns;
    use Concerns\HasColumnManager;
    use Concerns\HasContentLayout;
    use Concerns\HasCustomRecords;
    use Concerns\HasGroups;
    use Concerns\HasLiveUpdates;
    use Concerns\HasPaginationMode;
    use Concerns\HasReorderingOptions;
    use Concerns\HasSavedViews;
    use Concerns\HasSpreadsheet;
    use Concerns\HasSummaries;
    use Concerns\HasTriggerActions;
    use Concerns\PersistsStateInSession;
    use Configurable;
    use EvaluatesClosures;
    use Macroable;

    protected Builder|Closure|null $query = null;

    protected ?Closure $modifyQueryUsing = null;

    /** @var class-string<Model>|null */
    protected ?string $model = null;

    /** @var array<Column>|Closure */
    protected array|Closure $columns = [];

    /** @var array<Column|Columns\ColumnGroup|Columns\Layout\Component> Columns added with `pushColumns()`. */
    protected array $pushedColumns = [];

    /** @var array<BaseFilter>|Closure */
    protected array|Closure $filters = [];

    /** @var array<BaseFilter> Filters added with `pushFilters()`. */
    protected array $pushedFilters = [];

    protected FiltersLayout|string|Closure $filtersLayout = 'dropdown';

    protected int|array|Closure|null $filtersFormColumns = null;

    protected Width|string|Closure|null $filtersFormWidth = null;

    protected string|Closure|null $filtersFormMaxHeight = null;

    protected ?Closure $filtersFormSchema = null;

    protected bool|Closure $hasHiddenFilterIndicators = false;

    /** @var bool|array<string>|Closure|null Table-level search: forced on/off, or extra attributes to search. */
    protected bool|array|Closure|null $isTableSearchable = null;

    protected bool|Closure $shouldSplitSearchTerms = true;

    protected bool|Closure $shouldSplitIndividualSearchTerms = false;

    protected ?Closure $searchUsing = null;

    protected bool|Closure $isStackedOnMobile = false;

    protected int|Closure|null $maxSelectableRecords = null;

    protected bool|Closure $isSelectGroupsOnly = false;

    /** @var array<Action|ActionGroup>|Closure */
    protected array|Closure $recordActions = [];

    protected RecordActionsPosition|string|Closure $recordActionsPosition = RecordActionsPosition::AfterColumns;

    /** @var array<Action|ActionGroup>|Closure */
    protected array|Closure $headerActions = [];

    /** @var array<BulkAction|ActionGroup>|Closure */
    protected array|Closure $bulkActions = [];

    /** @var array<Action|BulkAction|ActionGroup>|Closure */
    protected array|Closure $toolbarActions = [];

    protected ?Closure $modifyUngroupedRecordActionsUsing = null;

    protected string|Closure|null $heading = null;

    protected string|Closure|null $description = null;

    protected string|Closure|null $defaultSortColumn = null;

    protected string|Closure $defaultSortDirection = 'asc';

    protected string|Closure|null $defaultSortOptionLabel = null;

    protected bool|Closure $hasDefaultKeySort = true;

    protected bool|Closure $isPaginated = true;

    protected bool|Closure $isSimplePaginated = false;

    /** @var array<int|string>|Closure */
    protected array|Closure $paginationPageOptions = [10, 25, 50, 100];

    protected int|string|Closure $defaultPaginationPageOption = 10;

    protected string|Closure|null $searchPlaceholder = null;

    protected int|Closure $searchDebounce = 500;

    protected bool|Closure $isSearchOnBlur = false;

    protected bool|Closure $isStriped = false;

    protected string|Closure|null $recordUrl = null;

    protected bool|Closure $shouldOpenRecordUrlInNewTab = false;

    protected string|Closure|null $recordAction = null;

    protected ?Closure $recordClasses = null;

    protected ?Closure $checkIfRecordIsSelectable = null;

    /** Null: rows are selectable when the table has bulk actions. */
    protected bool|Closure|null $isSelectable = null;

    protected bool|Closure $isSelectCurrentPageOnly = false;

    protected bool|Closure $shouldDeselectAllRecordsWhenFiltered = true;

    protected string|Closure|null $pollingInterval = null;

    protected string|Closure|null $reorderColumn = null;

    protected bool|Closure $isReorderAuthorized = true;

    protected string|Closure $reorderDirection = 'asc';

    protected string|Closure|null $emptyStateHeading = null;

    protected string|Closure|null $emptyStateDescription = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $emptyStateIcon = null;

    /** @var array<Action>|Closure */
    protected array|Closure $emptyStateActions = [];

    protected string|Closure|null $modelLabel = null;

    protected string|Closure|null $pluralModelLabel = null;

    protected ?string $queryStringIdentifier = null;

    protected mixed $context = null;

    protected TableState $state;

    /** @var LengthAwarePaginator<array-key, mixed>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed>|null */
    protected LengthAwarePaginator|Paginator|CursorPaginator|null $paginator = null;

    /** @var Collection<array-key, mixed>|null Models, or rows of custom `records()`. */
    protected ?Collection $records = null;

    protected bool|Closure $isDeferLoading = false;

    /** @var array<string, Column>|null */
    protected ?array $resolvedColumns = null;

    /** @var array<string, BaseFilter>|null */
    protected ?array $resolvedFilters = null;

    /** @var array<string, mixed> */
    protected array $arguments = [];

    /**
     * Visible columns and prepared record actions while `serializeRecords()` runs.
     *
     * @var array{columns: array<string, Column>, actions: array<Action|ActionGroup>}|null
     */
    protected ?array $serializingRows = null;

    final public function __construct(mixed $context = null)
    {
        $this->context = $context;
        $this->state = TableState::empty();
    }

    public static function make(mixed $context = null): static
    {
        $static = new static($context);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'table';
    }

    // ---------------------------------------------------------------- Query

    public function query(Builder|Closure|null $query): static
    {
        $this->query = $query;

        if ($query instanceof Builder) {
            $this->model = $query->getModel()::class;
        }

        return $this;
    }

    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * @param  class-string<Model>|null  $model
     */
    public function model(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @return class-string<Model>|null
     */
    public function getModel(): ?string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        // Tables over custom `records()` have no query, and so no model.
        $model = $this->getQuery()?->getModel();

        return $model === null ? null : $model::class;
    }

    public function getModelInstance(): ?Model
    {
        $model = $this->getModel();

        return $model !== null ? new $model : null;
    }

    /**
     * The base query, before table state is applied.
     */
    public function getQuery(): ?Builder
    {
        $query = $this->evaluate($this->query);

        if ($query === null && $this->model !== null) {
            $query = $this->model::query();
        }

        if ($query === null) {
            return null;
        }

        $query = clone $query;

        if ($this->modifyQueryUsing !== null) {
            $query = $this->evaluate($this->modifyQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }

    /**
     * The query with search, filters and sorting applied.
     */
    public function getFilteredSortedQuery(): ?Builder
    {
        $query = $this->getQuery();

        if ($query === null) {
            return null;
        }

        $query = $this->applyFiltersToBaseQuery($query);
        $this->applyFilters($query);
        $this->applySearch($query);
        $this->applyColumnSearches($query);
        // Rows are ordered by their group first so each group is contiguous.
        $this->applyGrouping($query);
        $this->applySort($query);
        $this->applyEagerLoading($query);

        return $query;
    }

    /**
     * Each filter's `baseQuery()`, applied to the base query itself.
     */
    protected function applyFiltersToBaseQuery(Builder $query): Builder
    {
        foreach ($this->getFilters() as $filter) {
            $query = $filter->applyToBaseQuery($query);
        }

        return $query;
    }

    protected function applyFilters(Builder $query): void
    {
        foreach ($this->getFilters() as $filter) {
            $filter->apply($query);
        }
    }

    protected function applySearch(Builder $query): void
    {
        $search = $this->state->search;

        if ($search === null || $this->evaluate($this->isTableSearchable) === false) {
            return;
        }

        // `searchUsing()` replaces the column search (e.g. Laravel Scout: `whereKey(Post::search($search)->keys())`).
        if ($this->searchUsing !== null) {
            $query->where(fn (Builder $query) => $this->evaluate($this->searchUsing, ['query' => $query, 'search' => $search], [Builder::class => $query]));

            return;
        }

        // Hidden columns are not part of the table, so they are neither searchable nor sortable.
        $columns = array_values(array_filter($this->getColumns(), static fn (Column $column): bool => ! $column->isHidden() && $column->isGloballySearchable()));

        if (($extra = $this->getExtraSearchColumn()) !== null) {
            $columns[] = $extra;
        }

        if ($columns === []) {
            return;
        }

        foreach ($this->splitSearch($search, $this->shouldSplitSearchTerms()) as $term) {
            $query->where(function (Builder $query) use ($columns, $term): void {
                foreach ($columns as $column) {
                    $column->applySearch($query, $term);
                }
            });
        }
    }

    protected function applyColumnSearches(Builder $query): void
    {
        foreach ($this->state->columnSearches as $name => $term) {
            $column = $this->getColumn($name);

            if ($column === null || $column->isHidden() || ! $column->isIndividuallySearchable()) {
                continue;
            }

            $split = $column->shouldSplitIndividualSearchTerms() ?? $this->shouldSplitIndividualSearchTerms();

            // Every term must match (AND), as for the table search.
            foreach ($this->splitSearch($term, $split) as $part) {
                $query->where(static fn (Builder $query) => $column->applySearch($query, $part));
            }
        }
    }

    /**
     * The search split into words, or the whole phrase when splitting is off.
     *
     * @return list<string>
     */
    protected function splitSearch(string $search, bool $split): array
    {
        if (! $split) {
            return trim($search) === '' ? [] : [trim($search)];
        }

        return array_values(array_filter(preg_split('/\s+/', $search) ?: [], static fn (string $term): bool => $term !== ''));
    }

    /**
     * A column searching the table-level `searchable([...])` attributes, which are not
     * shown as columns.
     */
    protected function getExtraSearchColumn(): ?Column
    {
        $attributes = $this->evaluate($this->isTableSearchable);

        if (! is_array($attributes) || $attributes === []) {
            return null;
        }

        return TextColumn::make('__table_search')->searchable(array_values(array_map(strval(...), $attributes)))->table($this);
    }

    /**
     * The sort the query actually uses: the requested column when it is visible and
     * sortable, otherwise the default sort (none while the table orders by its reorder
     * column).
     *
     * @return array{column: string, direction: string, requested: bool}|null
     */
    protected function getEffectiveSort(): ?array
    {
        $sortColumn = $this->state->sortColumn;
        $column = $sortColumn !== null ? $this->getColumn($sortColumn) : null;

        if ($sortColumn !== null && $column !== null && ! $column->isHidden() && $column->isSortable()) {
            return ['column' => $sortColumn, 'direction' => $this->state->sortDirection ?? 'asc', 'requested' => true];
        }

        if ($this->isReorderable()) {
            return null;
        }

        // `defaultSort(fn (Builder $query) => $query->orderBy(…))` names no column.
        if ($this->hasDefaultSortQuery()) {
            return null;
        }

        $defaultColumn = $this->evaluate($this->defaultSortColumn);

        return $defaultColumn === null ? null : ['column' => (string) $defaultColumn, 'direction' => (string) $this->evaluate($this->defaultSortDirection), 'requested' => false];
    }

    protected function applySort(Builder $query): void
    {
        $direction = $this->applySortOrders($query);

        if (! $this->hasDefaultKeySort()) {
            return;
        }

        // The primary key breaks ties so pages never repeat or skip rows (`defaultKeySort()`).
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();
        $orders = $query->getQuery()->orders ?? [];

        foreach ($orders as $order) {
            if (in_array($order['column'] ?? null, [$key, $model->getKeyName()], true)) {
                return;
            }
        }

        $query->orderBy($key, $orders === [] ? 'desc' : $direction);
    }

    /**
     * Order the query by the requested, reorder or default sort.
     *
     * @return string The direction the primary-key tiebreaker follows.
     */
    protected function applySortOrders(Builder $query): string
    {
        $sort = $this->getEffectiveSort();

        if ($sort !== null && $sort['requested']) {
            $this->getColumn($sort['column'])?->applySort($query, $sort['direction']);

            return $sort['direction'] === 'desc' ? 'desc' : 'asc';
        }

        if ($this->isReorderable()) {
            $query->orderBy($query->qualifyColumn($this->getReorderColumn()), $this->getReorderDirection());

            return $this->getReorderDirection();
        }

        if ($this->hasDefaultSortQuery()) {
            $direction = strtolower((string) $this->evaluate($this->defaultSortDirection)) === 'desc' ? 'desc' : 'asc';

            $this->evaluate($this->defaultSortColumn, ['query' => $query, 'direction' => $direction], [Builder::class => $query]);

            return $direction;
        }

        $defaultColumn = $this->evaluate($this->defaultSortColumn);

        if ($defaultColumn !== null) {
            $direction = $this->evaluate($this->defaultSortDirection);
            $column = $this->getColumn($defaultColumn);

            if ($column !== null) {
                $column->applySort($query, $direction);
            } else {
                $query->orderBy($query->qualifyColumn($defaultColumn), $direction);
            }

            return strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
        }

        return 'desc';
    }

    protected function applyEagerLoading(Builder $query): void
    {
        $relationships = [];

        foreach ($this->getColumns() as $column) {
            array_push($relationships, ...$column->getRelationshipsToEagerLoad());

            // `counts()`, `exists()`, `avg()`, … add their sub-selects (hidden columns too,
            // so a column can be sorted on before it is shown).
            $column->applyRelationshipAggregate($query);
        }

        if ($relationships !== []) {
            $query->with(array_values(array_unique($relationships)));
        }
    }

    /**
     * Resolve a single record by key: a model, or an array row of custom `records()`.
     * Trashed records are included by default so restore actions work; inline cell edits
     * pass `withTrashed: false`.
     *
     * @return Model|array<mixed>|null
     */
    public function getRecord(mixed $key, bool $withTrashed = true): Model|array|null
    {
        if ($key !== null && ! is_scalar($key)) {
            return null;
        }

        if ($this->hasCustomRecords()) {
            return $this->getCustomRecord($key);
        }

        $query = $this->getQuery();

        if ($query === null) {
            return null;
        }

        if ($withTrashed && in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withTrashed();
        }

        return $this->applyFiltersToBaseQuery($query)->whereKey($key)->first();
    }

    /**
     * @param  array<mixed>  $keys
     * @return Collection<array-key, mixed> Models, or array rows of custom `records()`.
     */
    public function getRecordsByKeys(array $keys): Collection
    {
        if ($this->hasCustomRecords()) {
            return $this->getCustomRecordsByKeys($keys);
        }

        $query = $this->getQuery();

        if ($query === null) {
            return new EloquentCollection;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withTrashed();
        }

        return $this->applyFiltersToBaseQuery($query)->whereKey($keys)->get();
    }

    /**
     * Every record matching the current filters/search (used by "select all" bulk actions).
     *
     * @return Collection<array-key, mixed>
     */
    public function getAllFilteredRecords(): Collection
    {
        if ($this->hasCustomRecords()) {
            return $this->getCustomRecordsCollection();
        }

        return $this->getFilteredSortedQuery()?->get() ?? new EloquentCollection;
    }

    // ---------------------------------------------------------------- State

    public function state(TableState $state): static
    {
        // A saved view fills what the request left out, before the session restores the rest.
        $this->state = $this->applyPersistedState($this->applyTableViewToState($state));
        $this->paginator = null;
        $this->records = null;

        foreach ($this->getFilters() as $filter) {
            $filter->state($this->state->filters[$filter->getName()] ?? $filter->getDefaultState() ?? []);
        }

        return $this;
    }

    public function getState(): TableState
    {
        return $this->state;
    }

    public function queryStringIdentifier(?string $identifier): static
    {
        $this->queryStringIdentifier = $identifier;

        return $this;
    }

    public function getQueryStringIdentifier(): ?string
    {
        return $this->queryStringIdentifier;
    }

    public function context(mixed $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): mixed
    {
        return $this->context;
    }

    /**
     * Arguments for a table configuration class, as Filament's `$table->getArguments()`
     * (set by `ModalTableSelect::tableArguments()`).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function arguments(array $arguments): static
    {
        $this->arguments = $arguments;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getResourceClass(): ?string
    {
        return $this->context instanceof HasResource ? $this->context->getResourceClass() : null;
    }

    /**
     * Check a policy ability for the table's model: through the resource when there is
     * one, otherwise straight against the model's policy (if it defines the ability). An
     * array row (custom `records()`) has no policy, so the ability is checked on the model
     * class, if any.
     *
     * @param  Model|array<mixed>|null  $record
     */
    public function authorizes(string $ability, Model|array|null $record = null): bool
    {
        if (is_array($record)) {
            $record = null;
        }

        $resource = $this->getResourceClass();

        if ($resource !== null) {
            return $resource::can($ability, $record);
        }

        $model = $record ?? $this->getQuery()?->getModel();

        return Authorization::allows($ability, $record ?? ($model === null ? null : $model::class));
    }

    // ---------------------------------------------------------------- Columns

    /**
     * @param  array<Column|Columns\ColumnGroup|Columns\Layout\Component>|Closure  $columns
     */
    public function columns(array|Closure $columns): static
    {
        $this->columns = $columns;
        $this->resolvedColumns = null;

        return $this;
    }

    /**
     * Add columns after the ones from `columns()`, which keeps them: handy in
     * `Table::configureUsing()` for columns every table shares.
     *
     * @param  array<Column|Columns\ColumnGroup|Columns\Layout\Component>  $columns
     */
    public function pushColumns(array $columns): static
    {
        $this->pushedColumns = [...$this->pushedColumns, ...array_values($columns)];
        $this->resolvedColumns = null;

        return $this;
    }

    /**
     * @return array<string, Column>
     */
    public function getColumns(): array
    {
        if ($this->resolvedColumns !== null) {
            return $this->resolvedColumns;
        }

        $columns = [];
        $this->resolvedColumnComponents = [...array_values($this->evaluate($this->columns) ?? []), ...$this->pushedColumns];

        // Column groups and layouts (`Split`, `Stack`, …) are flattened to their columns.
        foreach ($this->flattenColumnComponents($this->resolvedColumnComponents) as $column) {
            $column->table($this);
            $columns[$column->getName()] = $column;
        }

        return $this->resolvedColumns = $columns;
    }

    public function getColumn(string $name): ?Column
    {
        return $this->getColumns()[$name] ?? null;
    }

    /**
     * Columns currently shown, honouring toggles.
     *
     * @return array<string, Column>
     */
    public function getVisibleColumns(): array
    {
        $toggled = $this->state->toggledColumns;

        return array_filter($this->getColumns(), function (Column $column) use ($toggled): bool {
            if ($column->isHidden()) {
                return false;
            }

            if (! $column->isToggleable()) {
                return true;
            }

            if ($toggled === null) {
                return ! $column->isToggledHiddenByDefault();
            }

            return in_array($column->getName(), $toggled, true);
        });
    }

    public function hasToggleableColumns(): bool
    {
        foreach ($this->getColumns() as $column) {
            if ($column->isToggleable()) {
                return true;
            }
        }

        return false;
    }

    public function isSearchable(): bool
    {
        $searchable = $this->evaluate($this->isTableSearchable);

        if ($searchable === false) {
            return false;
        }

        if ($searchable === true || (is_array($searchable) && $searchable !== []) || $this->searchUsing !== null) {
            return true;
        }

        foreach ($this->getColumns() as $column) {
            if (! $column->isHidden() && $column->isGloballySearchable()) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- Filters

    /**
     * @param  array<BaseFilter>|Closure  $filters
     */
    public function filters(array|Closure $filters, FiltersLayout|string|Closure|null $layout = null): static
    {
        $this->filters = $filters;
        $this->resolvedFilters = null;

        if ($layout !== null) {
            $this->filtersLayout = $layout;
        }

        return $this;
    }

    /**
     * Add filters after the ones from `filters()`, which keeps them.
     *
     * @param  array<BaseFilter>  $filters
     */
    public function pushFilters(array $filters): static
    {
        $this->pushedFilters = [...$this->pushedFilters, ...array_values($filters)];
        $this->resolvedFilters = null;

        return $this;
    }

    public function filtersLayout(FiltersLayout|string|Closure $layout): static
    {
        $this->filtersLayout = $layout;

        return $this;
    }

    /**
     * @param  int|array<string, int|null>|Closure|null  $columns
     */
    public function filtersFormColumns(int|array|Closure|null $columns): static
    {
        $this->filtersFormColumns = $columns;

        return $this;
    }

    /**
     * Width of the filters dropdown, modal or sidebar (`Width::Large`, `'2xl'`, …).
     */
    public function filtersFormWidth(Width|string|Closure|null $width): static
    {
        $this->filtersFormWidth = $width;

        return $this;
    }

    public function getFiltersFormWidth(): ?string
    {
        $width = $this->evaluate($this->filtersFormWidth);

        return $width instanceof Width ? $width->value : ($width === null ? null : (string) $width);
    }

    /**
     * Maximum height of the filters dropdown (a CSS length such as `'400px'`); the form
     * scrolls inside it.
     */
    public function filtersFormMaxHeight(string|Closure|null $height): static
    {
        $this->filtersFormMaxHeight = $height;

        return $this;
    }

    public function getFiltersFormMaxHeight(): ?string
    {
        $height = $this->evaluate($this->filtersFormMaxHeight);

        // A CSS length only: it ends up in a style attribute.
        return is_string($height) && preg_match('/^[0-9.]+(px|rem|em|vh|dvh|svh|lvh|%)$/', trim($height)) === 1 ? trim($height) : null;
    }

    /**
     * Lay out the filters form yourself: `fn (array $filters): array` receives each filter's
     * fields as a group keyed by filter name, to place in sections, grids, …
     */
    public function filtersFormSchema(?Closure $callback): static
    {
        $this->filtersFormSchema = $callback;

        return $this;
    }

    /**
     * Hide the active filter badges above the table (the filters button still counts them).
     */
    public function hiddenFilterIndicators(bool|Closure $condition = true): static
    {
        $this->hasHiddenFilterIndicators = $condition;

        return $this;
    }

    public function hasHiddenFilterIndicators(): bool
    {
        return (bool) $this->evaluate($this->hasHiddenFilterIndicators);
    }

    /**
     * @return array<string, BaseFilter>
     */
    public function getFilters(): array
    {
        if ($this->resolvedFilters !== null) {
            return $this->resolvedFilters;
        }

        $filters = [];

        foreach ([...array_values($this->evaluate($this->filters) ?? []), ...$this->pushedFilters] as $filter) {
            if ($filter->isHidden()) {
                continue;
            }

            $filter->table($this);
            $filter->state($this->state->filters[$filter->getName()] ?? $filter->getDefaultState() ?? []);
            $filters[$filter->getName()] = $filter;
        }

        return $this->resolvedFilters = $filters;
    }

    public function getFilter(string $name): ?BaseFilter
    {
        return $this->getFilters()[$name] ?? null;
    }

    /**
     * The filters form as one schema: each filter's fields under its name, as the renderer
     * lays them out (`filters.{name}.{field}`). The schema endpoints use it to serve the
     * options of searchable select filters and to re-evaluate their closures.
     */
    public function getFiltersSchema(): Schema
    {
        $components = $this->getFiltersFormComponents();
        $optionFields = $this->getColumnOptionFields();

        // The option fields of `SelectColumn`s searched on the server live here too, under
        // `__columnOptions`, so the schema options endpoint can search them. They are never
        // rendered: the renderer builds the filters form from the table schema.
        if ($optionFields !== []) {
            $components[] = Group::make()->key('alfakit:column-options')->statePath(SelectColumn::OPTIONS_STATE_PATH)->schema($optionFields);
        }

        $schema = Schema::make()->components($components);

        if ($this->getModel() !== null) {
            $schema->model($this->getModel());
        }

        return $schema->fill(array_map(static fn (BaseFilter $filter): array => $filter->getState(), $this->getFilters()));
    }

    /**
     * The filters form: a group per filter (its fields under its name), laid out by
     * `filtersFormSchema()` when given.
     *
     * @return array<SchemaComponent>
     */
    protected function getFiltersFormComponents(): array
    {
        $groups = array_map(
            static fn (BaseFilter $filter): Group => Group::make()
                ->key('filter:'.$filter->getName())
                ->statePath($filter->getName())
                ->schema($filter->getForm())
                ->columnSpan($filter->getColumnSpan()),
            $this->getFilters(),
        );

        if ($this->filtersFormSchema === null) {
            return array_values($groups);
        }

        return array_values($this->evaluate($this->filtersFormSchema, ['filters' => $groups]) ?? []);
    }

    /**
     * The serialised `filtersFormSchema()` layout, for the renderer.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function serializeFiltersFormSchema(): ?array
    {
        if ($this->filtersFormSchema === null || $this->getFilters() === []) {
            return null;
        }

        $schema = Schema::make()->components($this->getFiltersFormComponents());

        if ($this->getModel() !== null) {
            $schema->model($this->getModel());
        }

        return $schema->fill(array_map(static fn (BaseFilter $filter): array => $filter->getState(), $this->getFilters()))->toSchema();
    }

    /**
     * @return array<Columns\Support\SelectColumnOptionsField>
     */
    protected function getColumnOptionFields(): array
    {
        $fields = [];

        foreach ($this->getColumns() as $column) {
            if ($column instanceof SelectColumn && ! $column->isHidden() && $column->hasRemoteOptions() && ! $column->isDisabled()) {
                $fields[] = $column->makeOptionsField();
            }
        }

        return $fields;
    }

    // ---------------------------------------------------------------- Actions

    /**
     * @param  array<Action|ActionGroup>|Closure  $actions
     */
    public function recordActions(array|Closure $actions, RecordActionsPosition|string|Closure|null $position = null): static
    {
        $this->recordActions = $actions;

        if ($position !== null) {
            $this->recordActionsPosition = $position;
        }

        return $this;
    }

    /**
     * Alias of `recordActions()`.
     *
     * @param  array<Action|ActionGroup>|Closure  $actions
     */
    public function actions(array|Closure $actions, RecordActionsPosition|string|Closure|null $position = null): static
    {
        return $this->recordActions($actions, $position);
    }

    public function recordActionsPosition(RecordActionsPosition|string|Closure $position): static
    {
        $this->recordActionsPosition = $position;

        return $this;
    }

    public function getRecordActionsPosition(): RecordActionsPosition
    {
        $position = $this->evaluate($this->recordActionsPosition);

        return $position instanceof RecordActionsPosition ? $position : (RecordActionsPosition::tryFrom((string) $position) ?? RecordActionsPosition::AfterColumns);
    }

    /**
     * @param  array<Action|ActionGroup>|Closure  $actions
     */
    public function headerActions(array|Closure $actions): static
    {
        $this->headerActions = $actions;

        return $this;
    }

    /**
     * @param  array<BulkAction|ActionGroup>|Closure  $actions
     */
    public function bulkActions(array|Closure $actions): static
    {
        $this->bulkActions = $actions;

        return $this;
    }

    /**
     * Shorthand for `bulkActions([BulkActionGroup::make($actions)])`: every bulk action in
     * one dropdown.
     *
     * @param  array<BulkAction|ActionGroup>  $actions
     */
    public function groupedBulkActions(array $actions): static
    {
        return $this->bulkActions([BulkActionGroup::make($actions)]);
    }

    /**
     * Filament's toolbar: bulk actions (and groups holding only bulk actions) appear when
     * records are selected, as with `bulkActions()`; other actions are shown in the header,
     * as with `headerActions()`. Adds to, does not replace, those two lists.
     *
     * @param  array<Action|BulkAction|ActionGroup>|Closure  $actions
     */
    public function toolbarActions(array|Closure $actions): static
    {
        $this->toolbarActions = $actions;

        return $this;
    }

    /**
     * Configure every record action that is not inside a group:
     * `fn (Action $action) => $action->iconButton()`.
     */
    public function modifyUngroupedRecordActionsUsing(?Closure $callback): static
    {
        $this->modifyUngroupedRecordActionsUsing = $callback;

        return $this;
    }

    /**
     * @return array<Action|ActionGroup>
     */
    public function getRecordActions(): array
    {
        $actions = $this->evaluate($this->recordActions) ?? [];

        if ($this->modifyUngroupedRecordActionsUsing !== null) {
            foreach ($actions as $index => $action) {
                if ($action instanceof Action) {
                    $modified = $this->evaluate($this->modifyUngroupedRecordActionsUsing, ['action' => $action], [Action::class => $action, $action::class => $action]);
                    $actions[$index] = $modified instanceof Action ? $modified : $action;
                }
            }
        }

        return $this->prepareActions($actions);
    }

    /**
     * @return array<Action|ActionGroup>
     */
    public function getHeaderActions(): array
    {
        return $this->prepareActions([...array_values($this->evaluate($this->headerActions) ?? []), ...$this->splitToolbarActions()[0]]);
    }

    /**
     * @return array<BulkAction|ActionGroup>
     */
    public function getBulkActions(): array
    {
        return $this->prepareActions([...array_values($this->evaluate($this->bulkActions) ?? []), ...$this->splitToolbarActions()[1]]);
    }

    /**
     * `toolbarActions()` split into header actions and bulk actions.
     *
     * @return array{0: list<Action|ActionGroup>, 1: list<BulkAction|ActionGroup>}
     */
    protected function splitToolbarActions(): array
    {
        $header = $bulk = [];

        foreach ($this->evaluate($this->toolbarActions) ?? [] as $action) {
            $flat = $action instanceof ActionGroup ? $action->getFlatActions() : [$action];
            $isBulk = $flat !== [] && array_filter($flat, static fn (Action $nested): bool => ! $nested instanceof BulkAction) === [];

            if ($isBulk) {
                /** @var BulkAction|ActionGroup $action */
                $bulk[] = $action;
            } else {
                $header[] = $action;
            }
        }

        return [$header, $bulk];
    }

    /**
     * @return array<Action>
     */
    public function getEmptyStateActions(): array
    {
        return $this->prepareActions($this->evaluate($this->emptyStateActions) ?? []);
    }

    /**
     * @template T of Action|ActionGroup
     *
     * @param  array<T>  $actions
     * @return array<T>
     */
    protected function prepareActions(array $actions): array
    {
        $resource = $this->getResourceClass();

        foreach ($actions as $action) {
            $action->context($this->context ?? $this);

            // Bulk actions enforce the selection limits; record actions that
            // `accessSelectedRecords()` resolve the selection through the table.
            foreach ($action instanceof ActionGroup ? $action->getFlatActions() : [$action] as $nested) {
                $nested->selectionTable($this);
            }

            if ($action instanceof Action) {
                $action->model($this->getModel());

                if ($resource !== null) {
                    $action->recordTitle(fn (Model|array|null $record): ?string => $record instanceof Model ? $resource::getRecordTitle($record) : null);
                }
            }
        }

        return $actions;
    }

    /**
     * Find a record or header action by name (searching inside groups).
     */
    public function getAction(string $name): ?Action
    {
        foreach ([...$this->getRecordActions(), ...$this->getHeaderActions(), ...$this->getEmptyStateActions()] as $action) {
            $found = $this->findAction($action, $name);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    public function getBulkAction(string $name): ?BulkAction
    {
        foreach ($this->getBulkActions() as $action) {
            $found = $this->findAction($action, $name);

            if ($found instanceof BulkAction) {
                return $found;
            }
        }

        return null;
    }

    protected function findAction(Action|ActionGroup $action, string $name): ?Action
    {
        if ($action instanceof ActionGroup) {
            foreach ($action->getFlatActions() as $nested) {
                if ($nested->getName() === $name) {
                    return $nested;
                }
            }

            return null;
        }

        return $action->getName() === $name ? $action : null;
    }

    // ---------------------------------------------------------------- Appearance

    public function heading(string|Closure|null $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * The sort used until the user sorts by a column: a column name, a closure returning
     * one, or (like Filament) a closure that orders the query itself and receives `$query`
     * and `$direction`: `defaultSort(fn (Builder $query) => $query->orderBy('position')->orderBy('name'))`.
     */
    public function defaultSort(string|Closure|null $column, string|Closure $direction = 'asc'): static
    {
        $this->defaultSortColumn = $column;
        $this->defaultSortDirection = $direction;

        return $this;
    }

    /**
     * Whether the default sort is a closure that orders the query (it takes a `$query`
     * parameter or a `Builder`), rather than one that returns a column name.
     */
    public function hasDefaultSortQuery(): bool
    {
        if (! $this->defaultSortColumn instanceof Closure) {
            return false;
        }

        foreach ((new ReflectionFunction($this->defaultSortColumn))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($parameter->getName() === 'query' || ($type instanceof ReflectionNamedType && is_a($type->getName(), Builder::class, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The label of the "default sort" option in the sort select of content layouts and
     * stacked tables (instead of "Sort by").
     */
    public function defaultSortOptionLabel(string|Closure|null $label): static
    {
        $this->defaultSortOptionLabel = $label;

        return $this;
    }

    public function getDefaultSortOptionLabel(): ?string
    {
        $label = $this->evaluate($this->defaultSortOptionLabel);

        return $label === null ? null : (string) $label;
    }

    /**
     * Order by the primary key after the other sorts (in their direction) so rows with
     * equal values keep a stable order across pages. On by default; turn it off for
     * tables without a primary key or when the query orders itself.
     */
    public function defaultKeySort(bool|Closure $condition = true): static
    {
        $this->hasDefaultKeySort = $condition;

        return $this;
    }

    public function hasDefaultKeySort(): bool
    {
        return (bool) $this->evaluate($this->hasDefaultKeySort);
    }

    /**
     * Paginate the records; an array sets the per-page options too (`paginated([10, 25, 50])`).
     *
     * @param  bool|array<int|string>|Closure  $condition
     */
    public function paginated(bool|array|Closure $condition = true): static
    {
        if (is_array($condition)) {
            $this->paginationPageOptions($condition);
            $condition = $condition !== [];
        }

        $this->isPaginated = $condition;

        return $this;
    }

    public function simplePaginated(bool|Closure $condition = true): static
    {
        $this->isSimplePaginated = $condition;

        return $this;
    }

    /**
     * @param  array<int|string>|Closure  $options  Integers, or `'all'`.
     */
    public function paginationPageOptions(array|Closure $options): static
    {
        $this->paginationPageOptions = $options;

        return $this;
    }

    public function defaultPaginationPageOption(int|string|Closure $option): static
    {
        $this->defaultPaginationPageOption = $option;

        return $this;
    }

    public function searchPlaceholder(string|Closure|null $placeholder): static
    {
        $this->searchPlaceholder = $placeholder;

        return $this;
    }

    public function searchDebounce(int|Closure $milliseconds): static
    {
        $this->searchDebounce = $milliseconds;

        return $this;
    }

    public function searchOnBlur(bool|Closure $condition = true): static
    {
        $this->isSearchOnBlur = $condition;

        return $this;
    }

    /**
     * Table-level search: `true` shows the search input even without searchable columns
     * (with `searchUsing()`), `false` hides it, and an array adds attributes that are
     * searched but not shown (`['id', 'author.email']`).
     *
     * @param  bool|array<string>|Closure  $condition
     */
    public function searchable(bool|array|Closure $condition = true): static
    {
        $this->isTableSearchable = $condition;

        return $this;
    }

    /**
     * Search each word separately (default) or the whole phrase (`false`, faster on large
     * tables and exact).
     */
    public function splitSearchTerms(bool|Closure $condition = true): static
    {
        $this->shouldSplitSearchTerms = $condition;

        return $this;
    }

    public function shouldSplitSearchTerms(): bool
    {
        return (bool) $this->evaluate($this->shouldSplitSearchTerms);
    }

    /**
     * Split the per-column searches into words too (off by default); a column's own
     * `splitIndividualSearchTerms()` wins.
     */
    public function splitIndividualSearchTerms(bool|Closure $condition = true): static
    {
        $this->shouldSplitIndividualSearchTerms = $condition;

        return $this;
    }

    public function shouldSplitIndividualSearchTerms(): bool
    {
        return (bool) $this->evaluate($this->shouldSplitIndividualSearchTerms);
    }

    /**
     * Replace the table search with your own: `fn (Builder $query, string $search)`. The
     * search input is shown even when no column is searchable.
     */
    public function searchUsing(?Closure $callback): static
    {
        $this->searchUsing = $callback;

        return $this;
    }

    /**
     * Below the `md` breakpoint, render each row as a card listing its cells with their
     * column labels. Selection and record actions keep working.
     */
    public function stackedOnMobile(bool|Closure $condition = true): static
    {
        $this->isStackedOnMobile = $condition;

        return $this;
    }

    public function isStackedOnMobile(): bool
    {
        return (bool) $this->evaluate($this->isStackedOnMobile);
    }

    public function striped(bool|Closure $condition = true): static
    {
        $this->isStriped = $condition;

        return $this;
    }

    public function recordUrl(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->recordUrl = $url;
        $this->shouldOpenRecordUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function openRecordUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenRecordUrlInNewTab = $condition;

        return $this;
    }

    /**
     * The name of a record action to run when a row is clicked.
     */
    public function recordAction(string|Closure|null $action): static
    {
        $this->recordAction = $action;

        return $this;
    }

    public function recordClasses(?Closure $callback): static
    {
        $this->recordClasses = $callback;

        return $this;
    }

    /**
     * Rows can be selected (default: when the table has bulk actions). `selectable()`
     * shows the checkboxes without bulk actions too, for record actions that
     * `accessSelectedRecords()`.
     */
    public function selectable(bool|Closure $condition = true): static
    {
        $this->isSelectable = $condition;

        return $this;
    }

    /**
     * Whether the selection checkboxes are shown.
     */
    public function isSelectionEnabled(): bool
    {
        $selectable = $this->evaluate($this->isSelectable);

        return $selectable === null ? $this->getBulkActions() !== [] : (bool) $selectable;
    }

    /**
     * The most records a user may select. "Select all" is hidden and bulk actions refuse
     * larger selections.
     */
    public function maxSelectableRecords(int|Closure|null $count): static
    {
        $this->maxSelectableRecords = $count;

        return $this;
    }

    public function getMaxSelectableRecords(): ?int
    {
        $count = $this->evaluate($this->maxSelectableRecords);

        return $count === null ? null : max(1, (int) $count);
    }

    /**
     * While the table is grouped, the selection is limited to the records of one group
     * (selected with the group's checkbox, or row by row).
     */
    public function selectGroupsOnly(bool|Closure $condition = true): static
    {
        $this->isSelectGroupsOnly = $condition;

        return $this;
    }

    public function isSelectGroupsOnly(): bool
    {
        return (bool) $this->evaluate($this->isSelectGroupsOnly);
    }

    public function hasRecordSelectabilityCheck(): bool
    {
        return $this->checkIfRecordIsSelectable !== null;
    }

    /**
     * The selected records (keys, or every filtered record), within the table's own query
     * and without the records that can't be selected. Enforces `maxSelectableRecords()`
     * and `selectGroupsOnly()`.
     *
     * @param  array<mixed>  $keys
     * @return Collection<array-key, mixed>
     */
    public function getSelectedRecords(array $keys, bool $all = false): Collection
    {
        $keys = array_values(array_filter($keys, is_scalar(...)));

        if (! $all && $keys === []) {
            return new EloquentCollection;
        }

        $records = ($this->hasCustomRecords() ? $this->resolveSelectedCustomRecords($keys, $all) : null)
            ?? ($all ? $this->getAllFilteredRecords() : $this->getRecordsByKeys($keys));
        $records = $records->filter(fn (Model|array $record): bool => $this->isRecordSelectable($record))->values();

        $this->ensureSelectionIsAllowed($records->count(), $records);

        return $records;
    }

    /**
     * Refuse a selection larger than `maxSelectableRecords()` or, with `selectGroupsOnly()`,
     * spanning several groups (422).
     *
     * @param  iterable<mixed>|null  $records  Records to check the groups of.
     */
    public function ensureSelectionIsAllowed(int $count, ?iterable $records = null): void
    {
        $max = $this->getMaxSelectableRecords();

        if ($max !== null && $count > $max) {
            abort(422, trans_choice('alfakit::tables.selection.max_exceeded', $max, ['count' => $max]));
        }

        if ($records === null || ! $this->isSelectGroupsOnly() || $this->getGrouping() === null) {
            return;
        }

        $groups = [];

        foreach ($records as $record) {
            if ($record instanceof Model || is_array($record)) {
                $groups[(string) $this->getRecordGroupKey($record)] = true;
            }

            if (count($groups) > 1) {
                abort(422, __('alfakit::tables.selection.one_group_only'));
            }
        }
    }

    public function checkIfRecordIsSelectableUsing(?Closure $callback): static
    {
        $this->checkIfRecordIsSelectable = $callback;

        return $this;
    }

    public function selectCurrentPageOnly(bool|Closure $condition = true): static
    {
        $this->isSelectCurrentPageOnly = $condition;

        return $this;
    }

    /**
     * Clear the bulk selection when the filters, search or list tab change, so a bulk
     * action never runs on records the user no longer sees. On by default.
     */
    public function deselectAllRecordsWhenFiltered(bool|Closure $condition = true): static
    {
        $this->shouldDeselectAllRecordsWhenFiltered = $condition;

        return $this;
    }

    public function shouldDeselectAllRecordsWhenFiltered(): bool
    {
        return (bool) $this->evaluate($this->shouldDeselectAllRecordsWhenFiltered);
    }

    /**
     * A fingerprint of what narrows the records (filters, search, column searches and the
     * list tab). Renderers clear the selection when it changes; null keeps the selection.
     */
    public function getSelectionScope(): ?string
    {
        // Under `selectGroupsOnly()` a new grouping clears the selection too.
        $grouping = $this->isSelectGroupsOnly() ? [$this->getGrouping()?->getId(), $this->getGroupingDirection()] : null;

        if (! $this->shouldDeselectAllRecordsWhenFiltered()) {
            return $grouping === null ? null : substr(md5((string) json_encode($grouping)), 0, 12);
        }

        if ($grouping !== null) {
            return substr(md5((string) json_encode([$grouping, $this->getUnscopedSelectionFingerprint()])), 0, 12);
        }

        return $this->getUnscopedSelectionFingerprint();
    }

    protected function getUnscopedSelectionFingerprint(): string
    {
        // `HasTabs::ACTIVE_TAB_QUERY_KEY`: list tabs narrow the query outside the table.
        $tab = request()->query('activeTab');

        return substr(md5((string) json_encode([
            $this->state->filters,
            $this->state->search,
            $this->state->columnSearches,
            is_scalar($tab) ? (string) $tab : null,
        ])), 0, 12);
    }

    public function poll(string|Closure|null $interval = '10s'): static
    {
        $this->pollingInterval = $interval;

        return $this;
    }

    public function reorderable(string|Closure|null $column = 'sort', bool|Closure $condition = true, string|Closure $direction = 'asc'): static
    {
        $this->reorderColumn = $column;
        $this->isReorderAuthorized = $condition;
        $this->reorderDirection = $direction;

        return $this;
    }

    /**
     * `asc` lists the lowest position first; `desc` lists the highest first.
     */
    public function getReorderDirection(): string
    {
        return strtolower((string) $this->evaluate($this->reorderDirection)) === 'desc' ? 'desc' : 'asc';
    }

    public function isReorderable(): bool
    {
        return $this->reorderColumn !== null && $this->evaluate($this->isReorderAuthorized);
    }

    public function getReorderColumn(): ?string
    {
        return $this->evaluate($this->reorderColumn);
    }

    public function deferLoading(bool|Closure $condition = true): static
    {
        $this->isDeferLoading = $condition;

        return $this;
    }

    public function emptyStateHeading(string|Closure|null $heading): static
    {
        $this->emptyStateHeading = $heading;

        return $this;
    }

    public function emptyStateDescription(string|Closure|null $description): static
    {
        $this->emptyStateDescription = $description;

        return $this;
    }

    public function emptyStateIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->emptyStateIcon = $icon;

        return $this;
    }

    /**
     * @param  array<Action>|Closure  $actions
     */
    public function emptyStateActions(array|Closure $actions): static
    {
        $this->emptyStateActions = $actions;

        return $this;
    }

    public function modelLabel(string|Closure|null $label): static
    {
        $this->modelLabel = $label;

        return $this;
    }

    public function pluralModelLabel(string|Closure|null $label): static
    {
        $this->pluralModelLabel = $label;

        return $this;
    }

    public function getModelLabel(): string
    {
        $label = $this->evaluate($this->modelLabel);

        if ($label !== null) {
            return $label;
        }

        $resource = $this->getResourceClass();

        if ($resource !== null) {
            return $resource::getModelLabel();
        }

        $model = $this->getModel();

        return $model !== null ? ModelLabel::singular($model) : __('alfakit::tables.record');
    }

    public function getPluralModelLabel(): string
    {
        $label = $this->evaluate($this->pluralModelLabel);

        if ($label !== null) {
            return $label;
        }

        $resource = $this->getResourceClass();

        if ($resource !== null) {
            return $resource::getPluralModelLabel();
        }

        $model = $this->getModel();

        if ($this->evaluate($this->modelLabel) === null && $model !== null) {
            return ModelLabel::plural($model);
        }

        return $model === null && $this->evaluate($this->modelLabel) === null
            ? __('alfakit::tables.records')
            : ModelLabel::pluralizeOverride($this->getModelLabel());
    }

    // ---------------------------------------------------------------- Records

    public function getPerPage(): int|string
    {
        $options = $this->evaluate($this->paginationPageOptions);
        $requested = $this->state->perPage;

        if ($requested !== null && in_array(is_numeric($requested) ? (int) $requested : $requested, $options, true)) {
            return is_numeric($requested) ? (int) $requested : $requested;
        }

        return $this->evaluate($this->defaultPaginationPageOption);
    }

    /**
     * The paginator for the current page, by pagination mode: length-aware (default),
     * simple, cursor, or a simple paginator over every record up to the current page
     * (load more / infinite scroll). Null when the table is not paginated.
     *
     * @return LengthAwarePaginator<array-key, mixed>|Paginator<array-key, mixed>|CursorPaginator<array-key, mixed>|null
     */
    public function getPaginator(): LengthAwarePaginator|Paginator|CursorPaginator|null
    {
        if ($this->paginator !== null) {
            return $this->paginator;
        }

        if (! $this->evaluate($this->isPaginated) || $this->getPerPage() === 'all' || $this->isUnpaginatedForReordering()) {
            return null;
        }

        $perPage = max(1, (int) $this->getPerPage());

        if ($this->hasCustomRecords()) {
            return $this->paginator = $this->getCustomRecordsPaginator($perPage);
        }

        $query = $this->getFilteredSortedQuery();

        if ($query === null) {
            return null;
        }

        return $this->paginator = $this->paginateQuery($query, $perPage);
    }

    /**
     * The records shown: models, or the rows of custom `records()` keyed by record key.
     *
     * @return Collection<array-key, mixed>
     */
    public function getRecords(): Collection
    {
        if ($this->records !== null) {
            return $this->records;
        }

        $paginator = $this->getPaginator();

        if ($paginator !== null) {
            return $this->records = $paginator->getCollection();
        }

        if ($this->hasCustomRecords()) {
            return $this->records = $this->getCustomRecordsCollection();
        }

        return $this->records = $this->getFilteredSortedQuery()?->get() ?? new EloquentCollection;
    }

    /**
     * @param  Model|array<mixed>  $record
     */
    public function isRecordSelectable(Model|array $record): bool
    {
        if ($this->evaluate($this->isSelectable) === false) {
            return false;
        }

        if ($this->checkIfRecordIsSelectable === null) {
            return true;
        }

        return (bool) $this->evaluate($this->checkIfRecordIsSelectable, ['record' => $record], $this->recordInjections($record));
    }

    /**
     * Typed closure injections for a record: models by class, array rows by nothing.
     *
     * @param  Model|array<mixed>  $record
     * @return array<class-string, mixed>
     */
    protected function recordInjections(Model|array $record): array
    {
        return $record instanceof Model ? [Model::class => $record, $record::class => $record] : [];
    }

    /**
     * Persist a new order for the given record keys.
     *
     * @param  array<mixed>  $order
     */
    public function reorderRecords(array $order): void
    {
        $column = $this->getReorderColumn();
        $query = $this->getQuery();

        if ($column === null || $query === null || ! $this->isReorderable()) {
            abort(403);
        }

        abort_unless($this->authorizes('reorder'), 403);

        $keys = array_values(array_filter($order, static fn (mixed $key): bool => is_scalar($key)));

        // Only records this table actually lists (same parent, same tenant, same filters
        // applied by the query) can be moved, and each one must be updatable.
        $records = (clone $query)->whereKey($keys)->get()->keyBy(static fn (Model $record): string => (string) $record->getKey());

        abort_if($records->count() !== count(array_unique(array_map('strval', $keys))), 404);

        foreach ($records as $record) {
            abort_unless($this->authorizes('update', $record), 403);
        }

        // `beforeReordering()` / `afterReordering()` receive the keys in their new order.
        $newOrder = array_values(array_unique(array_map('strval', $keys)));
        $this->callReorderingHook($this->beforeReordering, $newOrder);

        $descending = $this->getReorderDirection() === 'desc';

        // The renderer only sends the keys it shows (one page of a paginated table), so
        // the moved records swap the positions they already hold instead of being renumbered
        // 1..n, which would overwrite the positions of records on other pages.
        $positions = $records->map(static fn (Model $record): mixed => $record->getAttribute($column))->values()->all();
        $distinct = ! in_array(null, $positions, true) && count(array_unique(array_map('strval', $positions))) === count($positions);

        if ($distinct) {
            $descending ? rsort($positions) : sort($positions);

            foreach (array_values(array_unique(array_map('strval', $keys))) as $index => $key) {
                $this->writeReorderPosition($records[$key], $column, $positions[$index]);
            }

            $this->callReorderingHook($this->afterReordering, $newOrder);

            return;
        }

        // Missing or tied positions: renumber every listed record, keeping the moved
        // records in the slots they occupied and the rest where they were.
        $keyName = $records->first()?->getKeyName() ?? 'id';
        $listed = (clone $query)->reorder()
            ->orderBy($query->qualifyColumn($column), $descending ? 'desc' : 'asc')
            ->orderBy($query->qualifyColumn($keyName))
            ->pluck($keyName)
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
        $queue = array_values(array_unique(array_map('strval', $keys)));
        $moved = array_flip($queue);
        $sequence = [];

        foreach ($listed as $key) {
            $sequence[] = isset($moved[$key]) ? (string) array_shift($queue) : $key;
        }

        $count = count($sequence);

        foreach ($sequence as $index => $key) {
            $position = $descending ? $count - $index : $index + 1;

            if (isset($records[$key])) {
                $this->writeReorderPosition($records[$key], $column, $position);

                continue;
            }

            (clone $query)->reorder()->whereKey($key)->update([$column => $position]);
        }

        $this->callReorderingHook($this->afterReordering, $newOrder);
    }

    protected function writeReorderPosition(Model $record, string $column, mixed $position): void
    {
        if ((string) $record->getAttribute($column) === (string) $position) {
            return;
        }

        $record->newQuery()->whereKey($record->getKey())->update([$column => $position]);
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * @param  Model|array<mixed>  $record
     * @return array<string, mixed>
     */
    protected function serializeRecord(Model|array $record, int|string|null $key = null, ?stdClass $rowLoop = null): array
    {
        $cells = [];

        foreach ($this->serializingRows['columns'] ?? $this->getVisibleColumns() as $name => $column) {
            $cells[$name] = $column->rowLoop($rowLoop)->toCell($record);
            $column->rowLoop(null);
        }

        // Rows of custom `records()` are resolved again by their key (`getRecord()`), so they
        // carry record actions and can be selected like models.
        $recordKey = $record instanceof Model && ! $this->hasCustomRecords() ? $record->getKey() : $key;
        $injections = $this->recordInjections($record);
        $actions = [];

        foreach ($this->serializingRows['actions'] ?? $this->getRecordActions() as $action) {
            $action->record($record)->recordKey($recordKey);

            if ($action->isVisible()) {
                $actions[] = $action->toSchema();
            }

            $action->record(null);
        }

        $url = \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->recordUrl, ['record' => $record], $injections));

        return Serializable::compact([
            'key' => $recordKey,
            'url' => $url,
            'openUrlInNewTab' => $url !== null && $this->evaluate($this->shouldOpenRecordUrlInNewTab) ?: null,
            'selectable' => $this->isRecordSelectable($record) ?: null,
            'classes' => $this->recordClasses !== null ? $this->evaluate($this->recordClasses, ['record' => $record], $injections) : null,
            'group' => $this->getGrouping() !== null ? ($this->getRecordGroupKey($record) ?? '') : null,
            'cells' => $cells,
            'actions' => $actions ?: null,
        ]);
    }

    /**
     * The shown records, each with a Blade-style `$rowLoop` for column closures.
     *
     * @return list<array<string, mixed>>
     */
    protected function serializeRecords(): array
    {
        $records = $this->getRecords();
        $count = $records->count();
        $serialized = [];
        $index = 0;

        // The shown columns and the prepared record actions are the same for every row:
        // resolve them once per pass instead of once per row.
        $previous = $this->serializingRows;
        $this->serializingRows = ['columns' => $columns = $this->getVisibleColumns(), 'actions' => $this->getRecordActions()];

        foreach ($columns as $column) {
            $column->serializingRows();
        }

        try {
            foreach ($records as $key => $record) {
                $serialized[] = $this->serializeRecord($record, $key, self::makeRowLoop($index++, $count));
            }
        } finally {
            $this->serializingRows = $previous;

            foreach ($columns as $column) {
                $column->serializingRows(false);
            }
        }

        return $serialized;
    }

    /**
     * The loop variable of a row: `index`, `iteration`, `remaining`, `count`, `first`,
     * `last`, `odd`, `even`, `depth` and `parent`, as in a Blade `@foreach`.
     */
    public static function makeRowLoop(int $index, int $count): stdClass
    {
        return (object) [
            'index' => $index,
            'iteration' => $index + 1,
            'remaining' => $count - $index - 1,
            'count' => $count,
            'first' => $index === 0,
            'last' => $index === $count - 1,
            'odd' => ($index + 1) % 2 === 1,
            'even' => ($index + 1) % 2 === 0,
            'depth' => 1,
            'parent' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializePagination(): ?array
    {
        $paginator = $this->getPaginator();
        $options = $this->evaluate($this->paginationPageOptions);

        if ($paginator === null) {
            // Reorder mode lists every record: no pagination bar until it ends.
            if (! $this->evaluate($this->isPaginated) || $this->isUnpaginatedForReordering()) {
                return null;
            }

            $count = $this->getRecords()->count();

            return ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 'all', 'total' => $count, 'from' => $count ? 1 : 0, 'to' => $count, 'pageOptions' => $options];
        }

        return $this->serializePaginatorForMode($paginator, $options);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function serializeFilterIndicators(): array
    {
        $indicators = [];

        foreach ($this->getFilters() as $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                $indicators[] = $indicator instanceof Filters\Indicator
                    ? Serializable::compact([
                        'filter' => $filter->getName(),
                        'label' => $indicator->getLabel(),
                        'removeField' => $indicator->getRemoveField(),
                        'removable' => $indicator->isRemovable() ? null : false,
                    ])
                    : ['filter' => $filter->getName(), 'label' => (string) $indicator];
            }
        }

        return $indicators;
    }

    /**
     * @param  array<Action|ActionGroup>  $actions
     * @return array<int, array<string, mixed>>
     */
    protected function serializeActions(array $actions): array
    {
        return collect($actions)
            ->filter(static fn (Action|ActionGroup $action): bool => $action->isVisible())
            ->map(static fn (Action|ActionGroup $action): array => $action->toSchema())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $defer = (bool) $this->evaluate($this->isDeferLoading) && ! $this->state->loadDeferred;
        $filtersFormColumns = $this->evaluate($this->filtersFormColumns);
        $sort = $this->getEffectiveSort();

        return Serializable::compact([
            'type' => 'Table',
            'identifier' => $this->queryStringIdentifier,
            'heading' => $this->evaluate($this->heading),
            'description' => $this->evaluate($this->description),
            'modelLabel' => $this->getModelLabel(),
            'pluralModelLabel' => $this->getPluralModelLabel(),
            'columns' => array_values(array_map(static fn (Column $column): array => $column->toSchema(), array_filter($this->applyColumnOrder($this->getColumns()), static fn (Column $column): bool => ! $column->isHidden()))),
            'visibleColumns' => array_keys($this->applyColumnOrder($this->getVisibleColumns())),
            'columnGroups' => $this->getColumnGroupsSchema(),
            'contentLayout' => $this->getContentLayoutSchema(),
            'contentGrid' => $this->getContentGrid(),
            'reorderableColumns' => $this->hasReorderableColumns() ?: null,
            'toggleableColumns' => $this->hasToggleableColumns() ?: null,
            'filters' => array_values(array_map(static fn (BaseFilter $filter): array => $filter->toSchema(), $this->getFilters())) ?: null,
            'filtersLayout' => $this->getFiltersLayout(),
            'deferFilters' => $this->hasDeferredFilters() ?: null,
            'persistedState' => $this->getPersistedStateKeys() ?: null,
            'filtersFormColumns' => $filtersFormColumns === null ? null : (is_array($filtersFormColumns) ? $filtersFormColumns : ['default' => 1, 'lg' => $filtersFormColumns]),
            'filtersFormWidth' => $this->getFiltersFormWidth(),
            'filtersFormMaxHeight' => $this->getFiltersFormMaxHeight(),
            'filtersFormSchema' => $this->serializeFiltersFormSchema(),
            'hiddenFilterIndicators' => $this->hasHiddenFilterIndicators() ?: null,
            'filterState' => $this->state->filters ?: null,
            'filterIndicators' => $this->serializeFilterIndicators() ?: null,
            'headerActions' => $this->serializeActions($this->getHeaderActions()) ?: null,
            'bulkActions' => $this->serializeActions($this->getBulkActions()) ?: null,
            'recordActionsPosition' => $this->getRecordActionsPosition()->value,
            'recordAction' => $this->evaluate($this->recordAction),
            'records' => $defer ? null : $this->serializeRecords(),
            'pagination' => $defer ? null : $this->serializePagination(),
            'sort' => $sort === null ? null : ['column' => $sort['column'], 'direction' => $sort['direction']],
            'defaultSortOptionLabel' => $this->getDefaultSortOptionLabel(),
            'triggerActions' => $this->serializeTriggerActions() ?: null,
            ...$this->serializeToolbarOptions(),
            'search' => $this->state->search,
            'columnSearches' => $this->state->columnSearches ?: null,
            'searchable' => $this->isSearchable() ?: null,
            'stackedOnMobile' => $this->isStackedOnMobile() ?: null,
            'searchPlaceholder' => $this->evaluate($this->searchPlaceholder) ?? __('alfakit::tables.search.placeholder'),
            'searchDebounce' => $this->evaluate($this->searchDebounce),
            'searchOnBlur' => $this->evaluate($this->isSearchOnBlur) ?: null,
            'striped' => $this->evaluate($this->isStriped) ?: null,
            'selectable' => $this->isSelectionEnabled() ?: null,
            'selectCurrentPageOnly' => $this->evaluate($this->isSelectCurrentPageOnly) ?: null,
            'maxSelectableRecords' => $this->isSelectionEnabled() ? $this->getMaxSelectableRecords() : null,
            'selectGroupsOnly' => $this->isSelectionEnabled() && $this->isSelectGroupsOnly() && $this->getGrouping() !== null ? true : null,
            'selectionScope' => $this->isSelectionEnabled() ? $this->getSelectionScope() : null,
            'pollingInterval' => $this->evaluate($this->pollingInterval),
            'live' => $this->getTableLiveSchema(),
            'views' => $this->getTableViewsSchema(),
            'spreadsheet' => $this->serializeSpreadsheet(),
            'reorderable' => $this->isReorderable() ?: null,
            ...$this->serializeReorderingOptions(),
            'deferLoading' => $defer ?: null,
            'emptyState' => [
                'heading' => $this->evaluate($this->emptyStateHeading) ?? __('alfakit::tables.empty.heading', ['label' => $this->getPluralModelLabel()]),
                'description' => $this->evaluate($this->emptyStateDescription),
                'icon' => Icons::resolve($this->evaluate($this->emptyStateIcon) ?? 'alfakit::tables.empty-state'),
                'actions' => $this->serializeActions($this->getEmptyStateActions()) ?: null,
            ],
            'summaries' => $this->hasCustomRecords() ? null : $this->serializeSummaries(),
            // `deferTotals()`: the total and summaries the renderer loads after the rows.
            'deferredTotals' => $defer ? null : $this->getDeferredTotalsSchema(),
            'virtualize' => $this->getVirtualizeSchema(),
            // Last, so a groups-only table can replace `records` and `pagination`.
            ...$this->serializeGrouping(),
        ]);
    }

    public function getFiltersLayout(): string
    {
        $layout = $this->evaluate($this->filtersLayout);

        return $layout instanceof FiltersLayout ? $layout->value : (string) $layout;
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'query' => [$this->getQuery()],
            'model' => [$this->getModel()],
            'state' => [$this->state],
            'context' => [$this->context],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        return match (true) {
            $parameterType === TableState::class => [$this->state],
            $this->context instanceof $parameterType => [$this->context],
            default => [],
        };
    }
}
