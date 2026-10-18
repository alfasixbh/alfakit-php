<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Tables\TableState;
use Closure;

/**
 * Remember filters, search, sort, records per page, toggled columns and grouping in the
 * session, per panel, tenant, user and table. `persistInSession()` turns every kind on
 * (or, with `false`, off); the per-feature methods override it.
 *
 * A request that carries a key in its query string (even empty: `?search=`) is
 * authoritative and is stored; a request without it gets the stored value back. The
 * renderers send empty keys for cleared state of persisted tables, so clearing sticks.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait PersistsStateInSession
{
    protected bool|Closure|null $persistsFiltersInSession = null;

    protected bool|Closure|null $persistsSearchInSession = null;

    protected bool|Closure|null $persistsColumnSearchesInSession = null;

    protected bool|Closure|null $persistsSortInSession = null;

    protected bool|Closure|null $persistsRecordsPerPageInSession = null;

    protected bool|Closure|null $persistsColumnsInSession = null;

    protected bool|Closure|null $persistsGroupInSession = null;

    /** The default of every per-feature setting left unset. */
    protected bool|Closure $persistsInSession = false;

    /**
     * Persist the whole table state (filters, search, column searches, sort, records per
     * page, toggled columns and grouping); `false` disables all of it. Per-feature methods
     * called on the table win over this.
     */
    public function persistInSession(bool|Closure $condition = true): static
    {
        $this->persistsInSession = $condition;

        return $this;
    }

    public function persistRecordsPerPageInSession(bool|Closure $condition = true): static
    {
        $this->persistsRecordsPerPageInSession = $condition;

        return $this;
    }

    /**
     * Remember which toggleable columns are shown.
     */
    public function persistColumnsInSession(bool|Closure $condition = true): static
    {
        $this->persistsColumnsInSession = $condition;

        return $this;
    }

    /**
     * Remember the grouping the user picked and its direction.
     */
    public function persistGroupInSession(bool|Closure $condition = true): static
    {
        $this->persistsGroupInSession = $condition;

        return $this;
    }

    public function persistFiltersInSession(bool|Closure $condition = true): static
    {
        $this->persistsFiltersInSession = $condition;

        return $this;
    }

    public function persistSearchInSession(bool|Closure $condition = true): static
    {
        $this->persistsSearchInSession = $condition;

        return $this;
    }

    public function persistColumnSearchesInSession(bool|Closure $condition = true): static
    {
        $this->persistsColumnSearchesInSession = $condition;

        return $this;
    }

    public function persistSortInSession(bool|Closure $condition = true): static
    {
        $this->persistsSortInSession = $condition;

        return $this;
    }

    /**
     * The query-string keys this table keeps in the session.
     *
     * @return list<'filters'|'search'|'columnSearch'|'sort'|'perPage'|'columns'|'group'>
     */
    public function getPersistedStateKeys(): array
    {
        $all = (bool) $this->evaluate($this->persistsInSession);
        $resolve = fn (bool|Closure|null $setting): bool => $setting === null ? $all : (bool) $this->evaluate($setting);

        return array_keys(array_filter([
            'filters' => $resolve($this->persistsFiltersInSession),
            'search' => $resolve($this->persistsSearchInSession),
            'columnSearch' => $resolve($this->persistsColumnSearchesInSession),
            'sort' => $resolve($this->persistsSortInSession),
            'perPage' => $resolve($this->persistsRecordsPerPageInSession),
            'columns' => $resolve($this->persistsColumnsInSession),
            'group' => $resolve($this->persistsGroupInSession),
        ]));
    }

    /**
     * The session key for this table: panel, tenant, user, owner class and identifier.
     */
    public function getPersistenceSessionKey(): string
    {
        $panel = alfakit()->getCurrentOrDefaultPanel()->getId();
        $tenant = alfakit()->getTenant()?->getKey();
        $owner = is_object($this->context) ? $this->context::class : (string) $this->getModel();

        return 'alfakit.tables.'.$panel.'.'.sha1(implode('|', [
            is_scalar($tenant) ? (string) $tenant : '',
            SchemaContext::currentUser() ?? '',
            $owner,
            $this->getQueryStringIdentifier() ?? '',
        ]));
    }

    /**
     * Store the persisted keys the request carried and restore the ones it did not.
     */
    protected function applyPersistedState(TableState $state): TableState
    {
        $keys = $this->getPersistedStateKeys();

        if ($keys === [] || ! app()->bound('session')) {
            return $state;
        }

        $sessionKey = $this->getPersistenceSessionKey();
        $stored = session()->get($sessionKey);
        $stored = is_array($stored) ? $stored : [];
        $changed = false;
        $requestedGroupDirection = $state->groupDirection;

        foreach ($keys as $key) {
            if (in_array($key, $state->present, true)) {
                $stored[$key] = match ($key) {
                    'filters' => $state->filters,
                    'search' => $state->search,
                    'columnSearch' => $state->columnSearches,
                    'sort' => $state->sortColumn === null ? null : [$state->sortColumn, $state->sortDirection],
                    'perPage' => $state->perPage,
                    'columns' => $state->toggledColumns,
                    'group' => $state->group === null ? null : [$state->group, $state->groupDirection],
                };
                $changed = true;

                continue;
            }

            if (! array_key_exists($key, $stored)) {
                continue;
            }

            $value = $stored[$key];

            match ($key) {
                'filters' => $state->filters = is_array($value) ? $value : [],
                'search' => $state->search = is_string($value) && $value !== '' ? $value : null,
                'columnSearch' => $state->columnSearches = is_array($value) ? array_filter($value, static fn (mixed $term, mixed $name): bool => is_string($name) && is_string($term), ARRAY_FILTER_USE_BOTH) : [],
                'sort' => [$state->sortColumn, $state->sortDirection] = is_array($value) && is_string($value[0] ?? null)
                    ? [$value[0], in_array($value[1] ?? null, ['asc', 'desc'], true) ? $value[1] : null]
                    : [null, null],
                'perPage' => $state->perPage = is_int($value) || (is_string($value) && $value !== '') ? $value : null,
                'columns' => $state->toggledColumns = is_array($value) ? array_values(array_filter($value, is_string(...))) : null,
                'group' => [$state->group, $state->groupDirection] = is_array($value) && is_string($value[0] ?? null)
                    ? [$value[0], in_array($value[1] ?? null, ['asc', 'desc'], true) ? $value[1] : null]
                    : [null, null],
            };
        }

        // A new group direction without a group (the group came from the session) keeps
        // the stored group and stores the new direction.
        if (in_array('group', $keys, true) && ! in_array('group', $state->present, true) && in_array('groupDirection', $state->present, true) && is_array($stored['group'] ?? null)) {
            $state->groupDirection = $requestedGroupDirection;
            $stored['group'] = [$state->group, $state->groupDirection];
            $changed = true;
        }

        if ($changed) {
            session()->put($sessionKey, $stored);
        }

        return $state;
    }
}
