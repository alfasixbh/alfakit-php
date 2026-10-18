<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\Concerns;

use AlfaSix\Alfakit\Resources\Pages\Enums\ContentTabPosition;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationGroup;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManagerConfiguration;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;

trait HasRelationManagers
{
    /** @var array<string, RelationManager>|null */
    protected ?array $cachedRelationManagers = null;

    /**
     * Every relation manager class, groups flattened.
     *
     * @return array<class-string<RelationManager>>
     */
    protected function getRelationManagerClasses(): array
    {
        return array_values(array_unique(array_map(
            static fn (array $definition): string => $definition['class'],
            $this->getRelationManagerDefinitions(),
        )));
    }

    /**
     * Every manager of `getRelations()` (groups flattened) with its properties
     * (`make([...])`) and its key (`['comments' => …]`), keyed by its identifier suffix
     * (`Class` or `Class@key`).
     *
     * @return array<string, array{class: class-string<RelationManager>, properties: array<string, mixed>, key: string|null}>
     */
    protected function getRelationManagerDefinitions(): array
    {
        $definitions = [];

        foreach ($this->getRelationManagerEntries() as $key => $entry) {
            if ($entry instanceof RelationGroup) {
                foreach ($entry->getManagers() as $memberKey => $member) {
                    $definition = $this->makeRelationManagerDefinition($member, is_string($memberKey) ? $memberKey : null);
                    $definitions[$definition['id']] ??= $definition;
                }

                continue;
            }

            $definition = $this->makeRelationManagerDefinition($entry, is_string($key) ? $key : null);
            $definitions[$definition['id']] ??= $definition;
        }

        return array_map(static fn (array $definition): array => [
            'class' => $definition['class'],
            'properties' => $definition['properties'],
            'key' => $definition['key'],
        ], $definitions);
    }

    /**
     * @param  class-string<RelationManager>|RelationManagerConfiguration  $entry
     * @return array{id: string, class: class-string<RelationManager>, properties: array<string, mixed>, key: string|null}
     */
    protected function makeRelationManagerDefinition(string|RelationManagerConfiguration $entry, ?string $key): array
    {
        $class = $entry instanceof RelationManagerConfiguration ? $entry->getRelationManager() : $entry;

        return [
            'id' => $class.($key !== null ? '@'.$key : ''),
            'class' => $class,
            'properties' => $entry instanceof RelationManagerConfiguration ? $entry->getProperties() : [],
            'key' => $key,
        ];
    }

    /**
     * The relation managers of this page: manager classes, `make([...])` configurations and
     * `RelationGroup`s, optionally keyed (`['comments' => CommentsRelationManager::class]`):
     * a key names the manager's tab in `?relation=` and prefixes its table's query string.
     * Defaults to the resource's `getRelations()`; override to show other managers on this
     * page only.
     *
     * @return array<class-string<RelationManager>|RelationManagerConfiguration|RelationGroup>
     */
    public function getAllRelationManagers(): array
    {
        return static::getResource()::getRelations();
    }

    /**
     * @return array<class-string<RelationManager>|RelationManagerConfiguration|RelationGroup>
     */
    protected function getRelationManagerEntries(): array
    {
        return $this->getAllRelationManagers();
    }

    /**
     * Show the form (Edit) or infolist (View) as a tab next to the relation manager tabs
     * instead of above them.
     */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return false;
    }

    /** Label of the form / infolist tab; defaults to "Edit" or "View". */
    public function getContentTabLabel(): ?string
    {
        return null;
    }

    public function getContentTabIcon(): ?string
    {
        return null;
    }

    /**
     * The form / infolist tab (label, icon, badge). Defaults to `getContentTabLabel()` and
     * `getContentTabIcon()`.
     */
    public function getContentTabComponent(): Tab
    {
        return Tab::make($this->getContentTabLabel() ?? ($this instanceof ViewRecord
            ? __('alfakit::panel.resources.view.breadcrumb')
            : __('alfakit::panel.resources.edit.breadcrumb')))
            ->icon($this->getContentTabIcon());
    }

    /** Where the form / infolist tab sits; defaults to before the relation managers. */
    public function getContentTabPosition(): ?ContentTabPosition
    {
        return null;
    }

    /**
     * The tabs of a combined block, in order: the content tab under the empty key and the
     * relation manager tabs under their `getRelations()` keys. Override to place the
     * content tab anywhere (or reorder or leave out relation tabs):
     *
     * ```php
     * public function getCombinedTabComponents(): array
     * {
     *     return [
     *         'comments' => $this->getRelationManagerTabComponent('comments'),
     *         '' => $this->getContentTabComponent(),
     *         'tags' => $this->getRelationManagerTabComponent('tags'),
     *     ];
     * }
     * ```
     *
     * Only the order is used: tabs are drawn from the content tab and the relation managers
     * (tabs with their own schema are not supported).
     *
     * @return array<string|int, Tab|null>
     */
    public function getCombinedTabComponents(): array
    {
        $tabs = $this->getRelationManagerTabComponents(except: ['']);
        $contentTab = ['' => $this->getContentTabComponent()];

        return match ($this->getContentTabPosition()) {
            ContentTabPosition::After => $tabs + $contentTab,
            default => $contentTab + $tabs,
        };
    }

    /**
     * A tab per entry of `getRelationManagers()` the user may see, keyed like it.
     *
     * @param  array<string|int>  $except
     * @return array<string|int, Tab>
     */
    public function getRelationManagerTabComponents(array $except = []): array
    {
        $tabs = [];

        foreach (array_keys(array_diff_key($this->getRelationManagerEntries(), array_flip($except))) as $key) {
            if (($tab = $this->getRelationManagerTabComponent($key)) !== null) {
                $tabs[$key] = $tab;
            }
        }

        return $tabs;
    }

    /**
     * The tab (label, icon, badge) of the relation manager or group under `$key` in
     * `getRelations()`; null when it is unknown or hidden from the user.
     */
    public function getRelationManagerTabComponent(string|int $key): ?Tab
    {
        $identifier = $this->getRelationTabIdentifier($key);

        if ($identifier === null) {
            return null;
        }

        $entry = $this->getRelationManagerEntries()[$key];
        $record = $this->getRecord();

        if ($entry instanceof RelationGroup) {
            return Tab::make($entry->getLabel($record))->icon($entry->getIcon($record))->badge($entry->getBadge($record));
        }

        $manager = $this->getRelationManager(substr($identifier, strlen('relationManager:')));

        return $manager === null || $record === null ? null : $manager::getTabComponent($record, static::class);
    }

    /**
     * The renderer's tab key of an entry of `getRelations()`: the manager's component
     * (`relationManager:Class@key`) or the group's key. Null when the user can't see it.
     */
    protected function getRelationTabIdentifier(string|int $key): ?string
    {
        $entry = $this->getRelationManagerEntries()[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        $managers = $this->getRelationManagers();

        if ($entry instanceof RelationGroup) {
            foreach ($entry->getManagers() as $memberKey => $member) {
                if (isset($managers[$this->makeRelationManagerDefinition($member, is_string($memberKey) ? $memberKey : null)['id']])) {
                    return $entry->getKey();
                }
            }

            return null;
        }

        $id = $this->makeRelationManagerDefinition($entry, is_string($key) ? $key : null)['id'];

        return isset($managers[$id]) ? $managers[$id]->getIdentifier() : null;
    }

    /**
     * With `hasCombinedRelationManagerTabsWithContent()`, move the page's own block
     * (`$contentKey`: `form` or `infolist`) into the relation managers block as its
     * content tab. Pages without relation managers keep the block as it is.
     *
     * @param  array<string, array<string, mixed>|null>  $embedded
     * @return array<string, array<string, mixed>|null>
     */
    protected function combineRelationManagerTabsWithContent(array $embedded, string $contentKey): array
    {
        $managers = $embedded['relationManagers'] ?? null;
        $content = $embedded[$contentKey] ?? null;

        if (! $this->hasCombinedRelationManagerTabsWithContent() || $managers === null || $content === null) {
            return $embedded;
        }

        $combined = array_filter($this->getCombinedTabComponents());
        $tab = $combined[''] ?? $this->getContentTabComponent();
        $badge = $tab->getBadge();

        // `getCombinedTabComponents()`: the tab keys in order (`content` for the content tab).
        $order = [];

        foreach (array_keys($combined) as $key) {
            $order[] = $key === '' ? 'content' : $this->getRelationTabIdentifier($key);
        }

        $order = array_values(array_filter($order));
        $default = array_values(array_filter(array_map(fn (string|int $key): ?string => $this->getRelationTabIdentifier($key), array_keys($this->getRelationManagerEntries()))));
        $isCustomOrder = $order !== ['content', ...$default] && $order !== [...$default, 'content'];

        $managers['content'] = array_filter([
            'label' => (string) $tab->getLabel(),
            'icon' => $tab->getIcon(),
            'badge' => $badge === null ? null : (string) $badge,
            'badgeColor' => $tab->getBadgeColor(),
            'position' => ($order !== [] && end($order) === 'content' && $order[0] !== 'content' ? ContentTabPosition::After : ($this->getContentTabPosition() ?? ContentTabPosition::Before))->value,
            'block' => $content,
        ], static fn (mixed $value): bool => $value !== null);

        if ($isCustomOrder) {
            $managers['tabOrder'] = $order;
        }

        $embedded[$contentKey] = null;
        $embedded['relationManagers'] = $managers;

        return $embedded;
    }

    /**
     * Groups with at least one visible manager, for the renderer's tabs.
     *
     * @param  array<string, RelationManager>  $managers
     * @return array<int, array<string, mixed>>
     */
    protected function serializeRelationGroups(array $managers): array
    {
        $groups = [];
        $record = $this->getRecord();

        foreach ($this->getRelationManagerEntries() as $key => $entry) {
            if (! $entry instanceof RelationGroup) {
                continue;
            }

            $members = [];

            foreach ($entry->getManagers() as $memberKey => $member) {
                $id = $this->makeRelationManagerDefinition($member, is_string($memberKey) ? $memberKey : null)['id'];

                if (isset($managers[$id])) {
                    $members[] = $managers[$id]->getIdentifier();
                }
            }

            if ($members === []) {
                continue;
            }

            // `deferBadge()`: the badge arrives with the deferred lazy components.
            $deferred = $entry->isBadgeDeferred($record) && $this->rendersLazyPlaceholders();

            $groups[] = array_filter([
                'key' => $entry->getKey(),
                'title' => $entry->getLabel($record),
                'icon' => $entry->getIcon($record),
                ...($deferred ? ['badgeDeferred' => true] : $entry->getBadgeSchema($record)),
                'slug' => is_string($key) ? $key : null,
                'managers' => $members,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $groups;
    }

    /**
     * The `deferBadge()` relation groups, by key.
     *
     * @return array<string, RelationGroup>
     */
    public function getDeferredBadgeRelationGroups(): array
    {
        $groups = [];
        $record = $this->getRecord();

        foreach ($this->getRelationManagerEntries() as $entry) {
            if ($entry instanceof RelationGroup && $entry->isBadgeDeferred($record)) {
                $groups[$entry->getKey()] = $entry;
            }
        }

        return $groups;
    }

    /**
     * @return array<string, RelationManager>
     */
    public function getRelationManagers(): array
    {
        if ($this->cachedRelationManagers !== null) {
            return $this->cachedRelationManagers;
        }

        $managers = [];
        $record = $this->getRecord();

        if ($record === null) {
            return $this->cachedRelationManagers = [];
        }

        foreach ($this->getRelationManagerDefinitions() as $id => ['class' => $class, 'properties' => $properties, 'key' => $key]) {
            if (! $class::canViewForRecord($record, static::class)) {
                continue;
            }

            $managers[$id] = $class::make($record, $this)->relationKey($key)->fillProperties($properties);
        }

        return $this->cachedRelationManagers = $managers;
    }

    /**
     * By identifier suffix (`Class` or `Class@key`); a bare class finds its first registration.
     */
    public function getRelationManager(string $id): ?RelationManager
    {
        $managers = $this->getRelationManagers();

        if (isset($managers[$id])) {
            return $managers[$id];
        }

        foreach ($managers as $manager) {
            if ($manager::class === $id) {
                return $manager;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeRelationManagers(): ?array
    {
        $managers = $this->getRelationManagers();

        if ($managers === []) {
            return null;
        }

        $groups = $this->serializeRelationGroups($managers);

        return [
            'type' => 'RelationManagers',
            'key' => 'relationManagers',
            ...($groups === [] ? [] : ['groups' => $groups]),
            'managers' => array_values(array_map(
                fn (RelationManager $manager): array => $this->rendersLazyPlaceholders() && $manager::isLazy() ? $manager->toLazySchema() : $manager->toSchema(),
                $managers,
            )),
        ];
    }
}
