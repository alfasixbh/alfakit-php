<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\CommandPalette\UserPreferences;
use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tables\Views\SavedTableView;
use AlfaSix\Alfakit\Tables\Views\TableView;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Saved table views: named table states (filters, search, sort, visible and ordered
 * columns, grouping, records per page) shown as tabs or a dropdown above the table.
 *
 * - `views([TableView::make('overdue')->filters([...])->default()])`: views defined in code.
 * - `savedViews()` (or the panel's `tableSavedViews()`): users save their own views and,
 *   with `manageSharedTableViews` (policy ability, or `canManageSharedViews()`), publish
 *   views to everyone on the same panel, table and tenant.
 *
 * The URL names the active view (`?view=slug`, prefixed like the other table keys) so it can
 * be shared; without it the user's default view (or the code view marked `default()`)
 * applies. Changes on top of a view stay in the URL as usual and mark the view modified.
 * Stored views are sanitised against the table's actual filters, columns and groups when
 * read, so a renamed column or a removed filter is simply dropped.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasSavedViews
{
    /** Most views one user may keep on one table. */
    public const MAX_SAVED_VIEWS = 50;

    /** Null: follow the panel's `tableSavedViews()`. */
    protected bool|Closure|null $hasSavedViews = null;

    /** @var array<TableView>|Closure */
    protected array|Closure $presetViews = [];

    protected string|Closure $viewsLayout = 'tabs';

    protected bool|Closure|null $canManageSharedViews = null;

    /** @var array<string, TableView>|null */
    protected ?array $resolvedPresetViews = null;

    /** @var Collection<int, SavedTableView>|null */
    protected ?Collection $resolvedUserViews = null;

    protected ?string $activeTableView = null;

    /** @var array<string, mixed>|null */
    protected ?array $activeTableViewState = null;

    /** @var array<string> State keys the request itself carried (before a view filled the rest). */
    protected array $tableViewRequestKeys = [];

    /** @var array<string>|null Column order a view imposes (tables without reorderable columns). */
    protected ?array $tableViewColumnOrder = null;

    /**
     * Let users save their own views of this table and, with permission, share them with
     * their team. `false` turns it off even when the panel enables it.
     */
    public function savedViews(bool|Closure|null $condition = true): static
    {
        $this->hasSavedViews = $condition;

        return $this;
    }

    public function hasSavedViews(): bool
    {
        $condition = $this->evaluate($this->hasSavedViews);

        if ($condition === null) {
            return alfakit()->getCurrentOrDefaultPanel()->hasTableSavedViews();
        }

        return (bool) $condition;
    }

    /**
     * Views defined in code, shown before the users' views.
     *
     * @param  array<TableView>|Closure  $views
     */
    public function views(array|Closure $views): static
    {
        $this->presetViews = $views;
        $this->resolvedPresetViews = null;

        return $this;
    }

    /**
     * `tabs` (default) or `dropdown`.
     */
    public function viewsLayout(string|Closure $layout): static
    {
        $this->viewsLayout = $layout;

        return $this;
    }

    public function getViewsLayout(): string
    {
        return $this->evaluate($this->viewsLayout) === 'dropdown' ? 'dropdown' : 'tabs';
    }

    /**
     * Who may publish, update and delete shared views. By default the table model's policy
     * decides through a `manageSharedTableViews` ability; without that method nobody can.
     */
    public function canManageSharedViews(bool|Closure|null $condition = true): static
    {
        $this->canManageSharedViews = $condition;

        return $this;
    }

    public function canManageSharedTableViews(): bool
    {
        if ($this->getTableViewsUser() === null) {
            return false;
        }

        if ($this->canManageSharedViews !== null) {
            return (bool) $this->evaluate($this->canManageSharedViews);
        }

        $model = $this->getModel();

        if ($model === null) {
            return false;
        }

        $policy = Gate::getPolicyFor($model);

        return $policy !== null && method_exists($policy, 'manageSharedTableViews') && $this->authorizes('manageSharedTableViews');
    }

    /**
     * @return array<string, TableView>
     */
    public function getPresetViews(): array
    {
        if ($this->resolvedPresetViews !== null) {
            return $this->resolvedPresetViews;
        }

        $views = [];

        foreach ($this->evaluate($this->presetViews) ?? [] as $view) {
            if ($view instanceof TableView && ! $view->isHidden()) {
                $views[$view->getName()] = $view;
            }
        }

        return $this->resolvedPresetViews = $views;
    }

    public function hasTableViews(): bool
    {
        return $this->getPresetViews() !== [] || $this->hasSavedViews();
    }

    /**
     * Whether this user can store views: saved views are on, someone is signed in and the
     * views table was migrated.
     */
    public function canSaveTableViews(): bool
    {
        return $this->hasSavedViews() && $this->getTableViewsUser() !== null && SavedTableView::isAvailable();
    }

    public function getTableViewsUser(): ?Authenticatable
    {
        return alfakit()->getCurrentOrDefaultPanel()->auth()->user();
    }

    /**
     * Identifies this table's views: the page, relation manager or widget class, the
     * query-string identifier and the model.
     */
    public function getTableViewsKey(): string
    {
        $owner = is_object($this->context) ? $this->context::class : (string) $this->context;

        return sha1(implode('|', [$owner, $this->getQueryStringIdentifier() ?? '', (string) $this->getModel()]));
    }

    public function getTableViewsTenantKey(): ?string
    {
        $tenant = alfakit()->getTenant()?->getKey();

        return is_scalar($tenant) ? (string) $tenant : null;
    }

    public function getTableViewQueryKey(): string
    {
        $identifier = $this->getQueryStringIdentifier();

        return $identifier === null ? 'view' : "{$identifier}_view";
    }

    /**
     * The views this user sees: their own and the shared ones (none without the table).
     *
     * @return Collection<int, SavedTableView>
     */
    public function getUserTableViews(): Collection
    {
        if ($this->resolvedUserViews !== null) {
            return $this->resolvedUserViews;
        }

        $user = $this->getTableViewsUser();

        if (! $this->hasSavedViews() || $user === null || ! SavedTableView::isAvailable()) {
            return $this->resolvedUserViews = new Collection;
        }

        $panel = alfakit()->getCurrentOrDefaultPanel()->getId();

        return $this->resolvedUserViews = SavedTableView::visibleTo($panel, $this->getTableViewsKey(), $this->getTableViewsTenantKey(), $user)->get()->values();
    }

    public function flushTableViews(): void
    {
        $this->resolvedUserViews = null;
    }

    public function findUserTableView(string $slug): ?SavedTableView
    {
        return $this->getUserTableViews()->first(static fn (SavedTableView $view): bool => $view->slug === $slug);
    }

    /**
     * Whether the user may update, rename or delete a stored view: their own personal
     * views, and shared views when they manage shared views.
     */
    public function canEditTableView(SavedTableView $view): bool
    {
        $user = $this->getTableViewsUser();

        if ($user === null) {
            return false;
        }

        return $view->is_shared ? $this->canManageSharedTableViews() : $view->isOwnedBy($user);
    }

    /**
     * The sanitised state of a view (code or stored) by key, or null for an unknown key.
     *
     * @return array<string, mixed>|null
     */
    public function getTableViewState(string $key): ?array
    {
        $preset = $this->getPresetViews()[$key] ?? null;

        if ($preset !== null) {
            return $this->sanitizeTableViewState($preset->getState());
        }

        $stored = $this->findUserTableView($key);

        return $stored === null ? null : $this->sanitizeTableViewState(is_array($stored->state) ? $stored->state : []);
    }

    protected function getTableViewDefaultPreferenceKey(): string
    {
        return 'table-view:'.sha1($this->getTableViewsKey().'|'.($this->getTableViewsTenantKey() ?? ''));
    }

    /**
     * The user's own default view (set with "Set as my default"), if it still exists.
     */
    public function getUserDefaultTableView(): ?string
    {
        $user = $this->getTableViewsUser();

        if ($user === null || ! $this->hasSavedViews()) {
            return null;
        }

        try {
            $key = UserPreferences::get(alfakit()->getCurrentOrDefaultPanel(), $user, $this->getTableViewDefaultPreferenceKey());
        } catch (Throwable) {
            return null;
        }

        // `''` is "no view" as the user's default, over a code default.
        if ($key === '') {
            return '';
        }

        return is_string($key) && $this->getTableViewState($key) !== null ? $key : null;
    }

    /**
     * Store (or, with null, forget) the user's default view: a view key, or `''` for none.
     */
    public function setUserDefaultTableView(?string $key): void
    {
        $user = $this->getTableViewsUser();

        abort_if($user === null, 403);

        UserPreferences::put(alfakit()->getCurrentOrDefaultPanel(), $user, $this->getTableViewDefaultPreferenceKey(), $key);
    }

    /**
     * The view applied without `?view=`: the user's default, else the first code view
     * marked `default()`.
     */
    public function getDefaultTableView(): ?string
    {
        $user = $this->getUserDefaultTableView();

        if ($user !== null) {
            return $user === '' ? null : $user;
        }

        foreach ($this->getPresetViews() as $name => $view) {
            if ($view->isDefault()) {
                return $name;
            }
        }

        return null;
    }

    public function getActiveTableView(): ?string
    {
        return $this->activeTableView;
    }

    /**
     * Fill the parts of the state the request did not carry from the active view. A view
     * named in the URL is authoritative (its unset parts are cleared, also over the session);
     * a default view only fills what neither the request nor the session hold.
     */
    protected function applyTableViewToState(TableState $state): TableState
    {
        $this->activeTableView = null;
        $this->activeTableViewState = null;
        $this->tableViewColumnOrder = null;
        $this->tableViewRequestKeys = $state->present;

        if (! $this->hasTableViews()) {
            return $state;
        }

        $request = request();
        $queryKey = $this->getTableViewQueryKey();
        $explicit = $request->query->has($queryKey);
        $requested = $request->query($queryKey);
        $requested = is_scalar($requested) ? trim((string) $requested) : '';

        // `?view=` (empty): explicitly no view, over any default.
        if ($explicit && $requested === '') {
            return $state;
        }

        $key = $explicit ? $requested : null;
        $viewState = $key !== null ? $this->getTableViewState($key) : null;

        // An unknown view (deleted, private to someone else) falls back to the default.
        if ($viewState === null) {
            $explicit = false;
            $key = $this->getDefaultTableView();
            $viewState = $key !== null ? $this->getTableViewState($key) : null;
        }

        if ($key === null || $viewState === null) {
            return $state;
        }

        $this->activeTableView = $key;
        $this->activeTableViewState = $viewState;

        $fill = static function (string $name) use ($state, $explicit, $viewState): bool {
            if (in_array($name, $state->present, true)) {
                return false;
            }

            if ($explicit) {
                $state->present[] = $name;

                return true;
            }

            return array_key_exists($name, $viewState);
        };

        if ($fill('filters')) {
            $state->filters = is_array($viewState['filters'] ?? null) ? $viewState['filters'] : [];
        }

        if ($fill('search')) {
            $state->search = is_string($viewState['search'] ?? null) && $viewState['search'] !== '' ? $viewState['search'] : null;
        }

        if ($fill('sort')) {
            $sort = $viewState['sort'] ?? null;
            [$state->sortColumn, $state->sortDirection] = is_array($sort) ? [$sort['column'], $sort['direction']] : [null, null];
        }

        if ($fill('perPage')) {
            $state->perPage = $viewState['perPage'] ?? null;
        }

        if (! in_array('groupDirection', $state->present, true) && $fill('group')) {
            $group = $viewState['group'] ?? null;
            [$state->group, $state->groupDirection] = is_array($group) ? [$group['id'], $group['direction']] : [null, null];
        }

        $columns = $viewState['columns'] ?? null;

        if ($fill('columns')) {
            $toggleable = array_keys(array_filter($this->getColumns(), static fn (Column $column): bool => $column->isToggleable()));
            $state->toggledColumns = is_array($columns) ? array_values(array_intersect($columns, $toggleable)) : null;
        }

        if (is_array($columns) && $columns !== []) {
            $this->applyTableViewColumnOrder($columns, $explicit);
        }

        return $state;
    }

    /**
     * @param  array<string>  $columns
     */
    protected function applyTableViewColumnOrder(array $columns, bool $explicit): void
    {
        if (! $this->hasReorderableColumns()) {
            $this->tableViewColumnOrder = $columns;

            return;
        }

        // Users reorder these columns themselves (kept in the session): picking the view
        // starts them from its order; a default view only fills an empty session.
        if ($explicit && $this->tableViewRequestKeys === [] && app()->bound('session')) {
            session()->put($this->getColumnOrderSessionKey(), $columns);
        } elseif (! $explicit && $this->getPersistedColumnOrder() === null) {
            $this->tableViewColumnOrder = $columns;
        }
    }

    /**
     * The table's current state, in the format saved views store.
     *
     * @return array<string, mixed>
     */
    public function captureTableViewState(): array
    {
        $state = $this->getState();

        return $this->sanitizeTableViewState([
            'filters' => $state->filters,
            'search' => $state->search,
            'sort' => $state->sortColumn === null ? null : ['column' => $state->sortColumn, 'direction' => $state->sortDirection ?? 'asc'],
            'columns' => array_keys($this->applyColumnOrder($this->getVisibleColumns())),
            'group' => $state->group === null ? null : ['id' => $state->group, 'direction' => $state->groupDirection],
            'perPage' => $state->perPage,
        ]);
    }

    /**
     * Keep only what this table understands: known filters (and their fields), sortable
     * columns, visible-capable columns, defined groups and offered page sizes. Unknown
     * keys are dropped, so stored views survive changes to the table.
     *
     * @param  array<mixed>  $state
     * @return array<string, mixed>
     */
    public function sanitizeTableViewState(array $state): array
    {
        $clean = [];

        if (array_key_exists('filters', $state)) {
            $clean['filters'] = $this->sanitizeTableViewFilters(is_array($state['filters']) ? $state['filters'] : []);
        }

        if (array_key_exists('search', $state)) {
            $search = $state['search'];
            $clean['search'] = is_scalar($search) && trim((string) $search) !== '' ? mb_substr(trim((string) $search), 0, 255) : null;
        }

        if (array_key_exists('sort', $state)) {
            $sort = $state['sort'];
            $column = is_array($sort) && is_string($sort['column'] ?? null) ? $this->getColumn($sort['column']) : null;
            $clean['sort'] = $column !== null && ! $column->isHidden() && $column->isSortable()
                ? ['column' => $column->getName(), 'direction' => ($sort['direction'] ?? null) === 'desc' ? 'desc' : 'asc']
                : null;
        }

        if (array_key_exists('columns', $state)) {
            $known = array_keys(array_filter($this->getColumns(), static fn (Column $column): bool => ! $column->isHidden()));
            $clean['columns'] = is_array($state['columns'])
                ? array_values(array_unique(array_filter($state['columns'], static fn (mixed $name): bool => is_string($name) && in_array($name, $known, true))))
                : null;
        }

        if (array_key_exists('group', $state)) {
            $group = $state['group'];
            $id = is_array($group) && is_string($group['id'] ?? null) ? $group['id'] : null;
            $direction = is_array($group) && in_array($group['direction'] ?? null, ['asc', 'desc'], true) ? $group['direction'] : null;
            $clean['group'] = $id !== null && ($id === '' || array_key_exists($id, $this->getGroups())) ? ['id' => $id, 'direction' => $direction] : null;
        }

        if (array_key_exists('perPage', $state)) {
            $perPage = $state['perPage'];
            $perPage = is_numeric($perPage) ? (int) $perPage : $perPage;
            $clean['perPage'] = in_array($perPage, (array) $this->evaluate($this->paginationPageOptions), true) ? $perPage : null;
        }

        return $clean;
    }

    /**
     * @param  array<mixed>  $filters
     * @return array<string, array<string, mixed>>
     */
    protected function sanitizeTableViewFilters(array $filters): array
    {
        $clean = [];

        foreach ($this->getFilters() as $name => $filter) {
            $value = $filters[$name] ?? null;

            if (! is_array($value)) {
                continue;
            }

            try {
                $fields = array_keys($filter->getFormSchema()->getFlatFields(withHidden: true));
            } catch (Throwable) {
                $fields = [];
            }

            $allowed = array_values(array_unique(array_map(static fn (string $path): string => explode('.', substr($path, strlen($name) + 1))[0], array_filter($fields, static fn (string $path): bool => str_starts_with($path, $name.'.')))));

            // A filter whose fields can't be listed (custom schema) keeps its whole state.
            $clean[$name] = $allowed === [] ? $value : array_intersect_key($value, array_flip($allowed));
        }

        return $clean;
    }

    /**
     * Whether the table differs from the active view in a part the request set itself.
     */
    public function isTableViewModified(): bool
    {
        if ($this->activeTableView === null || $this->activeTableViewState === null) {
            return false;
        }

        $view = $this->activeTableViewState;
        $state = $this->getState();

        foreach (array_unique($this->tableViewRequestKeys) as $key) {
            $differs = match ($key) {
                'filters' => self::normalizeViewValue($state->filters) != self::normalizeViewValue($view['filters'] ?? []),
                'search' => (string) $state->search !== (string) ($view['search'] ?? ''),
                'sort' => ($state->sortColumn === null ? null : [$state->sortColumn, $state->sortDirection ?? 'asc']) !== (is_array($view['sort'] ?? null) ? [$view['sort']['column'], $view['sort']['direction']] : null),
                'perPage' => (string) ($state->perPage ?? '') !== (string) ($view['perPage'] ?? ''),
                'group', 'groupDirection' => [(string) $state->group, (string) $state->groupDirection] !== (is_array($view['group'] ?? null) ? [(string) $view['group']['id'], (string) $view['group']['direction']] : ['', '']),
                'columns' => $this->tableViewColumnsDiffer($view['columns'] ?? null),
                default => false,
            };

            if ($differs) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>|null  $columns
     */
    protected function tableViewColumnsDiffer(?array $columns): bool
    {
        $toggleable = array_filter($this->getColumns(), static fn (Column $column): bool => $column->isToggleable() && ! $column->isHidden());
        $expected = $columns === null
            ? array_keys(array_filter($toggleable, static fn (Column $column): bool => ! $column->isToggledHiddenByDefault()))
            : array_values(array_intersect($columns, array_keys($toggleable)));
        $current = array_values(array_intersect(array_keys($this->getVisibleColumns()), array_keys($toggleable)));

        sort($expected);
        sort($current);

        return $expected !== $current;
    }

    /**
     * Filter state compared loosely: empty values dropped, scalars as strings, keys sorted.
     */
    protected static function normalizeViewValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $item = self::normalizeViewValue($item);

                if ($item !== null && $item !== '' && $item !== []) {
                    $result[$key] = $item;
                }
            }

            if (! array_is_list($result)) {
                ksort($result);
            }

            return $result;
        }

        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * The views payload for the renderer, or null when the table has no views.
     *
     * @return array<string, mixed>|null
     */
    public function getTableViewsSchema(): ?array
    {
        if (! $this->hasTableViews()) {
            return null;
        }

        $default = $this->getDefaultTableView();
        $items = [];

        foreach ($this->getPresetViews() as $name => $view) {
            $items[] = array_filter([
                'key' => $name,
                'label' => $view->getLabel(),
                'icon' => $view->getIcon(),
                'type' => 'preset',
                'default' => $name === $default ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        foreach ($this->getUserTableViews() as $view) {
            $items[] = array_filter([
                'key' => $view->slug,
                'label' => $view->name,
                'icon' => $view->icon,
                'type' => $view->is_shared ? 'shared' : 'personal',
                'editable' => $this->canEditTableView($view) ?: null,
                'default' => $view->slug === $default ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return array_filter([
            'layout' => $this->getViewsLayout(),
            'items' => $items,
            'active' => $this->activeTableView,
            'queryKey' => $this->getTableViewQueryKey(),
            'modified' => $this->isTableViewModified() ?: null,
            'canSave' => $this->canSaveTableViews() ?: null,
            'canShare' => $this->canSaveTableViews() && $this->canManageSharedTableViews() ?: null,
            // Whether choosing a default is possible (needs a signed-in user).
            'canSetDefault' => $this->hasSavedViews() && $this->getTableViewsUser() !== null ?: null,
            'userDefault' => $this->getUserDefaultTableView(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
