<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\RelationManagers;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\ModelLabel;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionProperty;

/**
 * Manages a relationship of the current record on edit/view pages: a table of related
 * records with create/edit/delete (and attach/detach) actions in modals.
 */
abstract class RelationManager implements HasResource
{
    protected static string $relationship;

    protected static ?string $title = null;

    protected static ?string $recordTitleAttribute = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    protected static ?string $icon = null;

    protected static ?string $badge = null;

    /** Colour of the tab badge (`primary`, `danger`…). */
    protected static ?string $badgeColor = null;

    /** Tooltip of the tab badge (e.g. what the number counts). */
    protected static ?string $badgeTooltip = null;

    /** @var class-string<resource>|null */
    protected static ?string $relatedResource = null;

    protected static bool $isLazy = true;

    /** BelongsToMany: let `AttachAction` attach a record that is already attached (Filament's property). */
    protected bool $allowsDuplicates = false;

    protected Model $ownerRecord;

    protected Page $page;

    protected ?Table $cachedTable = null;

    /**
     * Load the tab badge (`getBadge()`, colour and tooltip) in a request after the page
     * renders, for badges that run slow queries.
     */
    protected static bool $isBadgeDeferred = false;

    /** The key of the manager in `getRelations()` (`['comments' => …]`), used in URLs. */
    protected ?string $relationKey = null;

    /**
     * Properties given to `make([...])`.
     *
     * @var array<string, mixed>
     */
    protected array $makeProperties = [];

    /**
     * `make($ownerRecord, $page)` creates the manager for a page. `make(['status' =>
     * 'approved'])` in `getRelations()` registers it with properties that fill its public
     * properties (declare `public ?string $status = null;`).
     *
     * @param  Model|array<string, mixed>  $ownerRecord
     * @return ($ownerRecord is array ? RelationManagerConfiguration : static)
     */
    public static function make(Model|array $ownerRecord = [], ?Page $page = null): static|RelationManagerConfiguration
    {
        if (is_array($ownerRecord)) {
            return new RelationManagerConfiguration(static::class, $ownerRecord);
        }

        if ($page === null) {
            throw new InvalidArgumentException('Pass the page to '.static::class.'::make($ownerRecord, $page).');
        }

        $static = app(static::class);
        $static->ownerRecord = $ownerRecord;
        $static->page = $page;

        // A related resource nested under the page's resource links to its own pages under
        // the owner record (`/posts/{post}/comments/create`).
        $related = $static->getResourceClass();

        if ($related !== null && $page instanceof HasResource && $related::getParentResource() !== null && $related::getParentResource() === $page->getResourceClass()) {
            \AlfaSix\Alfakit\Resources\ParentRecords::set($related::getParentResource(), $ownerRecord);
        }

        return $static;
    }

    /**
     * Fill public properties (from `make([...])`).
     *
     * @param  array<string, mixed>  $properties
     */
    public function fillProperties(array $properties): static
    {
        foreach ($properties as $name => $value) {
            if (! is_string($name) || ! property_exists($this, $name)) {
                throw new InvalidArgumentException(sprintf('Relation manager [%s] has no property [%s]; declare it as a public property to pass it to make().', static::class, $name));
            }

            $property = new ReflectionProperty($this, $name);

            if (! $property->isPublic() || $property->isStatic() || $property->isReadOnly()) {
                throw new InvalidArgumentException(sprintf('Property [%s] of relation manager [%s] must be public, non-static and writable to be passed to make().', $name, static::class));
            }

            $this->{$name} = $value;
            $this->makeProperties[$name] = $value;
        }

        $this->cachedTable = null;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMakeProperties(): array
    {
        return $this->makeProperties;
    }

    /**
     * The key in `getRelations()`: the manager's table query-string prefix and its tab in
     * the `?relation=` query string.
     */
    public function relationKey(?string $key): static
    {
        $this->relationKey = $key;
        $this->cachedTable = null;

        return $this;
    }

    public function getRelationKey(): ?string
    {
        return $this->relationKey;
    }

    public static function isBadgeDeferred(): bool
    {
        return static::$isBadgeDeferred;
    }

    public static function getRelationshipName(): string
    {
        return static::$relationship;
    }

    /**
     * Read-only managers hide every action that changes data. By default they are
     * read-only on View pages (unless the panel has
     * `readOnlyRelationManagersOnResourceViewPagesByDefault(false)`) and when the user may
     * not update the owner record.
     */
    public function isReadOnly(): bool
    {
        if ($this->page instanceof \AlfaSix\Alfakit\Resources\Pages\ViewRecord && $this->page->getPanel()->hasReadOnlyRelationManagersOnResourceViewPagesByDefault()) {
            return true;
        }

        $ownerResource = $this->page instanceof HasResource ? $this->page->getResourceClass() : null;

        return $ownerResource !== null && ! $ownerResource::canEdit($this->ownerRecord);
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    public function allowsDuplicates(): bool
    {
        return $this->allowsDuplicates;
    }

    public function getOwnerRecord(): Model
    {
        return $this->ownerRecord;
    }

    public function getPage(): Page
    {
        return $this->page;
    }

    public function getRelationship(): Relation
    {
        return $this->ownerRecord->{static::getRelationshipName()}();
    }

    public function getResourceClass(): ?string
    {
        return static::$relatedResource;
    }

    public static function getTitle(): string
    {
        return static::$title ?? Labels::fromName(static::getRelationshipName());
    }

    /**
     * The tab badge; override for a computed one (e.g. a count of the related records).
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return static::$badge;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return static::$badgeColor;
    }

    public static function getBadgeTooltip(Model $ownerRecord, string $pageClass): ?string
    {
        return static::$badgeTooltip;
    }

    /**
     * The manager's tab: label, icon, badge, badge colour (and tooltip). Override to build
     * it in one place, e.g. `Tab::make('Comments')->badge($ownerRecord->comments()->count())`.
     * Defaults to `getTitle()`, `getIcon()`, `getBadge()` and `getBadgeColor()`.
     */
    public static function getTabComponent(Model $ownerRecord, string $pageClass): Tab
    {
        return Tab::make(static::getTitle())
            ->icon(static::getIcon())
            ->badge(static::getBadge($ownerRecord, $pageClass))
            ->badgeColor(static::getBadgeColor($ownerRecord, $pageClass));
    }

    /**
     * Title, icon and badge of the manager's tab, from `getTabComponent()`.
     *
     * @return array<string, string|bool>
     */
    protected function getTabSchema(bool $deferBadge = false): array
    {
        $page = $this->page::class;

        // `$isBadgeDeferred`: the badge arrives with the deferred lazy components, so
        // nothing that computes it (`getTabComponent()`, `getBadge()`) runs now.
        if ($deferBadge) {
            return [
                'title' => static::getTitle(),
                ...array_filter(['icon' => static::getIcon()], static fn (?string $value): bool => $value !== null && $value !== ''),
                'badgeDeferred' => true,
            ];
        }

        $tab = static::getTabComponent($this->ownerRecord, $page);
        $badge = $tab->getBadge();
        // `Tab::badgeTooltip()` where available, else the manager's `getBadgeTooltip()`.
        $tooltip = method_exists($tab, 'getBadgeTooltip') ? $tab->getBadgeTooltip() : null;

        return [
            'title' => (string) ($tab->getLabel() ?? static::getTitle()),
            ...array_filter([
                'icon' => $tab->getIcon(),
                'badge' => $badge === null ? null : (string) $badge,
                'badgeColor' => $tab->getBadgeColor(),
                'badgeTooltip' => is_string($tooltip) ? $tooltip : static::getBadgeTooltip($this->ownerRecord, $page),
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
        ];
    }

    /**
     * The tab icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public static function getIcon(): ?string
    {
        return Icons::resolve(static::$icon);
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return static::$recordTitleAttribute;
    }

    public function getModelLabel(): string
    {
        return static::$modelLabel ?? ModelLabel::singular($this->getRelationship()->getRelated()::class);
    }

    public function getPluralModelLabel(): string
    {
        return static::$pluralModelLabel
            ?? (static::$modelLabel !== null ? ModelLabel::pluralizeOverride(static::$modelLabel) : ModelLabel::plural($this->getRelationship()->getRelated()::class));
    }

    public function getRecordTitle(?Model $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $attribute = static::getRecordTitleAttribute();
        $title = $attribute !== null ? data_get($record, $attribute) : null;

        // Without a title attribute (or value), the model label names the record, as on resources.
        return filled($title) && is_scalar($title) ? (string) $title : Str::ucfirst($this->getModelLabel());
    }

    public function getIdentifier(): string
    {
        return 'relationManager:'.static::class.($this->relationKey !== null ? '@'.$this->relationKey : '');
    }

    /**
     * `badge`, `badgeColor` and `badgeTooltip` of the tab, for `$isBadgeDeferred` managers
     * (served by the deferred `relationManagerBadge:` target).
     *
     * @return array<string, string|bool>
     */
    public function getBadgeSchema(): array
    {
        return array_intersect_key($this->getTabSchema(), array_flip(['badge', 'badgeColor', 'badgeTooltip']));
    }

    /**
     * Lazy managers are left out of the first page response: the page renders a skeleton
     * in their place and loads them with an Inertia deferred prop right after.
     */
    public static function isLazy(): bool
    {
        return static::$isLazy;
    }

    // ---------------------------------------------------------------- Definition

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema;
    }

    abstract public function table(Table $table): Table;

    public function getTable(): Table
    {
        if ($this->cachedTable !== null) {
            return $this->cachedTable;
        }

        $identifier = $this->getTableQueryStringIdentifier();

        $table = Table::make($this)
            ->queryStringIdentifier($identifier)
            ->query(fn () => $this->getRelationshipQuery())
            ->modelLabel($this->getModelLabel())
            ->pluralModelLabel($this->getPluralModelLabel())
            ->heading($this->getTableHeading());

        $table = $this->table($table);

        foreach ([...$table->getRecordActions(), ...$table->getHeaderActions(), ...$table->getBulkActions(), ...$table->getEmptyStateActions()] as $action) {
            $this->configureAction($action);
        }

        $table->state(TableState::fromRequest(request(), $identifier));

        return $this->cachedTable = $table;
    }

    /**
     * The table's query. On a BelongsToMany it selects the related table's columns and the
     * `withPivot()` columns apart (`pivot_*`), then hydrates each record's `pivot` relation
     * like the relationship does, so `TextColumn::make('pivot.role')` (or `'role'`) reads the
     * pivot and a pivot column never overwrites a related column such as `id`.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Model>
     */
    protected function getRelationshipQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $relationship = $this->getRelationship();
        $query = $relationship->getQuery();

        if (! $relationship instanceof BelongsToMany) {
            return $query;
        }

        $columns = array_values(array_unique([
            $relationship->getForeignPivotKeyName(),
            $relationship->getRelatedPivotKeyName(),
            ...array_map('strval', $relationship->getPivotColumns()),
        ]));

        $query->select($relationship->getRelated()->qualifyColumn('*'));

        foreach ($columns as $column) {
            $query->addSelect($relationship->qualifyPivotColumn($column).' as pivot_'.$column);
        }

        $query->afterQuery(static function (mixed $results) use ($relationship): mixed {
            if (! $results instanceof \Illuminate\Support\Collection) {
                return $results;
            }

            foreach ($results as $record) {
                if (! $record instanceof Model) {
                    continue;
                }

                $attributes = [];

                foreach ($record->getAttributes() as $key => $value) {
                    if (str_starts_with($key, 'pivot_')) {
                        $attributes[substr($key, 6)] = $value;
                        unset($record->{$key});
                    }
                }

                if ($attributes !== []) {
                    $record->syncOriginal();
                    $record->setRelation($relationship->getPivotAccessor(), $relationship->newExistingPivot($attributes));
                }
            }

            return $results;
        });

        return $query;
    }

    /**
     * Prefix of the table's query-string keys, so several managers can share a page.
     */
    protected function getTableQueryStringIdentifier(): ?string
    {
        return $this->relationKey ?? Str::of(static::class)->classBasename()->kebab()->toString();
    }

    protected function getTableHeading(): ?string
    {
        return static::getTitle();
    }

    /**
     * Give relation-manager-aware defaults to table actions (modal forms from `form()`,
     * creation through the relationship).
     */
    protected function configureAction(Action|\AlfaSix\Alfakit\Actions\ActionGroup $action): void
    {
        if ($action instanceof \AlfaSix\Alfakit\Actions\ActionGroup) {
            foreach ($action->getFlatActions() as $nested) {
                $this->configureAction($nested);
            }

            return;
        }

        $action->context($this);
        $action->model($this->getRelationship()->getRelated()::class);

        if ($this->isReadOnly() && $this->isWriteAction($action)) {
            $action->hidden();
        }
        $action->recordTitle(fn (?Model $record): ?string => $this->getRecordTitle($record));

        // Create, edit and view get the manager's form in a modal, unless the related resource
        // is nested under the page's resource and has that page: then they link to it
        // (`/posts/{post}/comments/create`), whether or not the manager defines a form.
        if ($action instanceof \AlfaSix\Alfakit\Actions\CreateAction && ! $action->hasForm() && ! $this->linksToRelatedResourcePage('create')) {
            // CreateAction creates through the relationship (pivot fields included) itself.
            $action->form(fn (): array => $this->form(Schema::make())->getComponents(withHidden: true));
        }

        if ($action instanceof \AlfaSix\Alfakit\Actions\EditAction && ! $action->hasForm() && ! $this->linksToRelatedResourcePage('edit')) {
            $action->form(fn (): array => $this->form(Schema::make())->getComponents(withHidden: true));
        }

        if ($action instanceof \AlfaSix\Alfakit\Actions\ViewAction && ! $action->hasForm() && ! $this->linksToRelatedResourcePage('view')) {
            $action->form(fn (): array => $this->form(Schema::make())->getComponents(withHidden: true));
        }

        if ($action instanceof \AlfaSix\Alfakit\Actions\AttachAction || $action instanceof \AlfaSix\Alfakit\Actions\AssociateAction) {
            $action->relationManager($this);
        }
    }

    /**
     * Whether a create / edit / view action links to the related resource's own page
     * instead of a modal: the related resource is nested under the page's resource and
     * registers that page.
     */
    protected function linksToRelatedResourcePage(string $page): bool
    {
        $related = $this->getResourceClass();

        return $related !== null
            && $this->page instanceof HasResource
            && $related::getParentResource() !== null
            && $related::getParentResource() === $this->page->getResourceClass()
            && $related::hasPage($page);
    }

    protected function isWriteAction(Action $action): bool
    {
        foreach ([
            \AlfaSix\Alfakit\Actions\CreateAction::class, \AlfaSix\Alfakit\Actions\EditAction::class,
            \AlfaSix\Alfakit\Actions\DeleteAction::class, \AlfaSix\Alfakit\Actions\ForceDeleteAction::class,
            \AlfaSix\Alfakit\Actions\RestoreAction::class, \AlfaSix\Alfakit\Actions\ReplicateAction::class,
            \AlfaSix\Alfakit\Actions\AttachAction::class, \AlfaSix\Alfakit\Actions\DetachAction::class,
            \AlfaSix\Alfakit\Actions\AssociateAction::class, \AlfaSix\Alfakit\Actions\DissociateAction::class,
            \AlfaSix\Alfakit\Actions\DeleteBulkAction::class, \AlfaSix\Alfakit\Actions\ForceDeleteBulkAction::class,
            \AlfaSix\Alfakit\Actions\RestoreBulkAction::class,
            \AlfaSix\Alfakit\Actions\DetachBulkAction::class, \AlfaSix\Alfakit\Actions\DissociateBulkAction::class,
        ] as $class) {
            if ($action instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return [
            'type' => 'RelationManager',
            'key' => $this->getIdentifier(),
            'component' => $this->getIdentifier(),
            ...($this->relationKey !== null ? ['slug' => $this->relationKey] : []),
            ...$this->getTabSchema(static::isBadgeDeferred() && $this->page->rendersLazyPlaceholders()),
            'table' => $this->getTable()->toSchema(),
        ];
    }

    /**
     * The stand-in sent in place of a lazy manager until its schema arrives (tab label,
     * icon and badge, but no table).
     *
     * @return array<string, mixed>
     */
    public function toLazySchema(): array
    {
        return [
            'type' => 'RelationManager',
            'key' => $this->getIdentifier(),
            'component' => $this->getIdentifier(),
            ...($this->relationKey !== null ? ['slug' => $this->relationKey] : []),
            ...$this->getTabSchema(static::isBadgeDeferred()),
            'lazy' => true,
        ];
    }
}
