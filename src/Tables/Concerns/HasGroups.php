<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Grouping\Group;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Row grouping: `$table->groups([Group::make('status')])->defaultGroup('status')`.
 *
 * The active group comes from the `group` / `groupDirection` query-string keys (`?group=`
 * turns a default group off) and is limited to the groups the table defines.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasGroups
{
    /** @var array<Group|string>|Closure */
    protected array|Closure $groups = [];

    protected Group|string|Closure|null $defaultGroup = null;

    protected string|Closure|null $defaultGroupDirection = null;

    protected bool|Closure $isGroupingSettingsHidden = false;

    protected bool|Closure $isGroupingDirectionSettingHidden = false;

    protected bool|Closure $isGroupsOnly = false;

    protected bool|Closure $areGroupsCollapsedByDefault = false;

    /** Groups-only tables list at most this many groups. */
    protected int $maxGroups = 1000;

    /** @var array<string, Group>|null */
    protected ?array $resolvedGroups = null;

    /**
     * @param  array<Group|string>|Closure  $groups
     */
    public function groups(array|Closure $groups): static
    {
        $this->groups = $groups;
        $this->resolvedGroups = null;

        return $this;
    }

    /**
     * The group applied until the user picks another; `direction: 'desc'` lists the
     * default group's groups in descending order.
     */
    public function defaultGroup(Group|string|Closure|null $group, string|Closure|null $direction = null): static
    {
        $this->defaultGroup = $group;
        $this->defaultGroupDirection = $direction;
        $this->resolvedGroups = null;

        return $this;
    }

    /**
     * Hide the "group by" picker; the default group (if any) always applies.
     */
    public function groupingSettingsHidden(bool|Closure $condition = true): static
    {
        $this->isGroupingSettingsHidden = $condition;

        return $this;
    }

    public function groupingDirectionSettingHidden(bool|Closure $condition = true): static
    {
        $this->isGroupingDirectionSettingHidden = $condition;

        return $this;
    }

    /**
     * Show only the group headings and their summaries, not the rows.
     */
    public function groupsOnly(bool|Closure $condition = true): static
    {
        $this->isGroupsOnly = $condition;

        return $this;
    }

    public function collapsedGroupsByDefault(bool|Closure $condition = true): static
    {
        $this->areGroupsCollapsedByDefault = $condition;

        return $this;
    }

    /**
     * @return array<string, Group>
     */
    public function getGroups(): array
    {
        if ($this->resolvedGroups !== null) {
            return $this->resolvedGroups;
        }

        $groups = [];

        foreach ($this->evaluate($this->groups) ?? [] as $group) {
            $group = is_string($group) ? Group::make($group) : $group;
            $groups[$group->getId()] = $group;
        }

        $default = $this->evaluate($this->defaultGroup);

        if ($default instanceof Group && ! isset($groups[$default->getId()])) {
            $groups[$default->getId()] = $default;
        } elseif (is_string($default) && ! isset($groups[$default])) {
            $groups[$default] = Group::make($default);
        }

        return $this->resolvedGroups = $groups;
    }

    public function getDefaultGroup(): ?Group
    {
        $default = $this->evaluate($this->defaultGroup);
        $id = $default instanceof Group ? $default->getId() : $default;

        return $id === null ? null : ($this->getGroups()[$id] ?? null);
    }

    public function isGroupingSettingsHidden(): bool
    {
        return (bool) $this->evaluate($this->isGroupingSettingsHidden);
    }

    public function isGroupsOnly(): bool
    {
        return $this->getGrouping() !== null && (bool) $this->evaluate($this->isGroupsOnly);
    }

    /**
     * The group the rows are currently grouped by.
     */
    public function getGrouping(): ?Group
    {
        $requested = $this->state->group;

        if ($requested === null || $this->isGroupingSettingsHidden()) {
            return $this->getDefaultGroup();
        }

        return $requested === '' ? null : ($this->getGroups()[$requested] ?? $this->getDefaultGroup());
    }

    public function getGroupingDirection(): string
    {
        $default = $this->getDefaultGroupingDirection();

        if ((bool) $this->evaluate($this->isGroupingDirectionSettingHidden)) {
            return $default;
        }

        return $this->state->groupDirection ?? $default;
    }

    /**
     * `defaultGroup(direction:)` while the default group is active, `asc` otherwise.
     */
    protected function getDefaultGroupingDirection(): string
    {
        $direction = $this->evaluate($this->defaultGroupDirection);
        $default = $this->getDefaultGroup();

        if ($direction === null || $default === null || $this->getGrouping()?->getId() !== $default->getId()) {
            return 'asc';
        }

        return strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';
    }

    protected function applyGrouping(Builder $query): void
    {
        $group = $this->getGrouping();

        if ($group === null) {
            return;
        }

        $group->orderQuery($query, $this->getGroupingDirection(), $this);

        if (($relationship = $group->getRelationshipName()) !== null) {
            $query->with($relationship);
        }
    }

    /**
     * @param  Model|array<mixed>  $record  a model, or a row of custom `records()`
     */
    public function getRecordGroupKey(Model|array $record): ?string
    {
        return $this->getGrouping()?->getKey($record);
    }

    /**
     * The groups present on the current page (or, for groups-only tables, every group),
     * in display order.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function serializeRecordGroups(Group $group): array
    {
        $representatives = $this->isGroupsOnly() ? $this->getAllGroupRepresentatives($group) : $this->getPageGroupRepresentatives($group);
        $summaryQuery = $this->hasSummaries() && ! $this->hasCustomRecords() ? $this->getSummaryQuery() : null;
        $collapsible = $group->isCollapsible();
        $groups = [];

        // Keys are strings; '' is the group of rows without a value.
        foreach ($representatives as $key => $record) {
            $key = (string) $key;

            $groups[] = array_filter([
                'key' => $key,
                'title' => $group->getTitle($record),
                'description' => $group->getDescription($record),
                'icon' => $group->getIcon($record),
                'color' => $group->getColor($record),
                'summary' => $summaryQuery === null ? null : ($this->summarizeQuery($group->scopeQueryByKey(clone $summaryQuery, $key === '' ? null : $key)) ?: null),
                'collapsible' => $collapsible ?: null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $groups;
    }

    /**
     * @return array<string, Model|array<mixed>> first record of each group on the page, keyed by group key ('' for none)
     */
    protected function getPageGroupRepresentatives(Group $group): array
    {
        $representatives = [];

        foreach ($this->getRecords() as $record) {
            if ($record instanceof Model || is_array($record)) {
                $representatives[(string) $group->getKey($record)] ??= $record;
            }
        }

        return $representatives;
    }

    /**
     * Every group of the matching records (up to `$maxGroups`), found by streaming the
     * matching records once in group order.
     *
     * @return array<string, Model|array<mixed>>
     */
    protected function getAllGroupRepresentatives(Group $group): array
    {
        // Custom `records()`: every matching row is already in memory, sorted by group.
        if ($this->hasCustomRecords()) {
            $representatives = [];

            foreach ($this->getCustomRecordsCollection() as $record) {
                if ($record instanceof Model || is_array($record)) {
                    $representatives[(string) $group->getKey($record)] ??= $record;
                }

                if (count($representatives) >= $this->maxGroups) {
                    break;
                }
            }

            return $representatives;
        }

        $query = $this->getFilteredSortedQuery();

        if ($query === null) {
            return [];
        }

        $representatives = [];

        // `groupQueryUsing()`: one row per group from a grouped query, ordered by group.
        if ($group->hasGroupQuery()) {
            // Only the grouping matters: no sorting, eager loads or aggregate sub-selects.
            $base = (clone $query)->reorder()->setEagerLoads([]);
            $base->getQuery()->columns = null;
            $grouped = $group->groupQuery($base);

            if ($grouped !== null) {
                $grouped = $group->orderQuery($grouped, $this->getGroupingDirection(), $this);

                foreach ($grouped->limit($this->maxGroups)->get() as $record) {
                    /** @var Model $record */
                    $representatives[(string) $group->getKey($record)] ??= $record;
                }

                return $representatives;
            }
        }

        foreach ($query->cursor() as $record) {
            /** @var Model $record */
            $representatives[(string) $group->getKey($record)] ??= $record;

            if (count($representatives) >= $this->maxGroups) {
                break;
            }
        }

        return $representatives;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeGrouping(): array
    {
        $groups = $this->getGroups();

        if ($groups === []) {
            return [];
        }

        $group = $this->getGrouping();
        $hidden = $this->isGroupingSettingsHidden();

        $schema = [
            'groups' => $hidden ? null : array_values(array_map(static fn (Group $group): array => $group->toSchema(), $groups)),
            'groupingDirectionSettingHidden' => $this->evaluate($this->isGroupingDirectionSettingHidden) ?: null,
            'grouping' => $group === null ? null : array_filter([
                'group' => $group->getId(),
                'label' => $group->getLabel(),
                'direction' => $this->getGroupingDirection(),
                'collapsible' => $group->isCollapsible() ?: null,
                'collapsedByDefault' => $this->evaluate($this->areGroupsCollapsedByDefault) ?: null,
                'groupsOnly' => $this->isGroupsOnly() ?: null,
            ], static fn (mixed $value): bool => $value !== null),
            'recordGroups' => $group === null || $this->isWaitingForDeferredRecords() ? null : $this->serializeRecordGroups($group),
        ];

        if ($group !== null && $this->isGroupsOnly() && ! $this->isWaitingForDeferredRecords()) {
            // Only the group headings and summaries are shown: no rows and no pages.
            $schema['records'] = [];
            $schema['pagination'] = null;
        }

        return $schema;
    }
}
