<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources;

use AlfaSix\Alfakit\Clusters\Concerns\BelongsToCluster;
use AlfaSix\Alfakit\GlobalSearch\GlobalSearchResult;
use AlfaSix\Alfakit\Navigation\Concerns\HasNavigationSetters;
use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Navigation\NavigationState;
use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Pages\PageRegistration;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Concerns\IsNestedResource;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\ModelLabel;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Widgets\Widget;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;
use ReflectionProperty;
use UnitEnum;
use WeakMap;

/**
 * A resource describes how an Eloquent model is managed: its form, table, pages,
 * relation managers, navigation and authorisation.
 */
abstract class Resource
{
    use BelongsToCluster;
    use HasNavigationSetters;
    use IsNestedResource;

    protected static ?SubNavigationPosition $subNavigationPosition = null;

    /** @var class-string<Model>|null */
    protected static ?string $model = null;

    protected static ?string $slug = null;

    protected static ?string $modelLabel = null;

    protected static ?string $pluralModelLabel = null;

    /**
     * Whether navigation labels, list titles and breadcrumbs capitalise the model label
     * (`getTitleCase(Plural)ModelLabel()`). Turn off for labels that must keep their case.
     */
    protected static bool $hasTitleCaseModelLabel = true;

    protected static ?string $recordTitleAttribute = null;

    protected static ?string $recordRouteKeyName = null;

    protected static ?string $navigationIcon = null;

    protected static ?string $activeNavigationIcon = null;

    protected static ?string $navigationLabel = null;

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = null;

    protected static ?int $navigationSort = null;

    /** Shown when hovering the navigation badge (`getNavigationBadge()`). */
    protected static ?string $navigationBadgeTooltip = null;

    protected static bool $shouldRegisterNavigation = true;

    protected static bool $isGloballySearchable = true;

    protected static int $globalSearchResultsLimit = 50;

    /** Position of this resource's results in global search (lower first; unsorted last). */
    protected static ?int $globalSearchSort = null;

    /** `false` searches the whole phrase instead of each word separately. */
    protected static ?bool $shouldSplitGlobalSearchTerms = null;

    protected static bool $isScopedToTenant = true;

    /** `false` keeps this resource's records out of the command palette's recent records. */
    protected static bool $hasRecentRecords = true;

    /**
     * Record history on the edit and view pages (a "History" header action). `null` (default):
     * on when the model uses `HasRecordVersions`.
     */
    protected static ?bool $hasVersions = null;

    protected static ?string $tenantOwnershipRelationshipName = null;

    /**
     * Scope the records to the signed-in user: a column (`customer_id`) or a relationship
     * (`customer`) of the model pointing at the owner. `null` follows the panel's
     * `scopeToUser()`, `false` opts out of it.
     */
    protected static string|false|null $userOwnership = null;

    /** @var array<class-string, array{0: string|false|null, 1: Closure|null}> set by `scopeToUser()` */
    private static array $userOwnerships = [];

    protected static ?string $tenantRelationshipName = null;

    /**
     * `true` (default): without a policy, or without the policy method for an ability, the
     * ability is allowed (denied with the panel's `strictAuthorization()`). `false`: every
     * check goes straight to the Gate, so `Gate::before()` / `Gate::define()` decide and a
     * missing policy or policy method denies.
     */
    protected static bool $shouldCheckPolicyExistence = true;

    protected static bool $shouldSkipAuthorization = false;

    protected static bool $isDiscovered = true;

    /**
     * The options class of `make()` registrations (configurable resources); defaults to
     * `ResourceConfiguration`.
     *
     * @var class-string<ResourceConfiguration>|null
     */
    protected static ?string $configurationClass = null;

    /**
     * The registration serving the current request, by resource class.
     *
     * @var array<class-string, ResourceConfiguration|null>
     */
    private static array $activeConfigurations = [];

    /** @var WeakMap<object, array<string, string>>|null URLs built during the current request. */
    private static ?WeakMap $urlCache = null;

    /**
     * Slugs guessed from model class names (pure, and read for every URL a table builds).
     *
     * @var array<string, string>
     */
    private static array $guessedSlugs = [];

    // ---------------------------------------------------------------- Configurations

    /**
     * Register this resource again under `$key` with its own options:
     * `$panel->resources([OrderResource::make('archived')->archived()])`.
     */
    public static function make(string $key): ResourceConfiguration
    {
        $class = static::$configurationClass ?? ResourceConfiguration::class;

        return $class::make(static::class, $key);
    }

    /**
     * The registration serving the current request, or null for the plain registration.
     */
    public static function getConfiguration(): ?ResourceConfiguration
    {
        return self::$activeConfigurations[static::class] ?? null;
    }

    public static function hasConfiguration(): bool
    {
        return static::getConfiguration() !== null;
    }

    /**
     * Make `$configuration` the active registration of this resource (null for the plain
     * one). Pages and the panel do this for each request; prefer `withConfiguration()`.
     */
    public static function setConfiguration(?ResourceConfiguration $configuration): void
    {
        self::$activeConfigurations[static::class] = $configuration;
    }

    /**
     * Run `$callback` as another registration (a key of the current panel, a configuration,
     * or null for the plain registration), e.g. to link to it.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withConfiguration(ResourceConfiguration|string|null $configuration, Closure $callback, ?Panel $panel = null): mixed
    {
        if (is_string($configuration)) {
            $key = $configuration;
            $configuration = ($panel ?? alfakit()->getCurrentOrDefaultPanel())->getResourceConfiguration(static::class, $key);

            if ($configuration === null) {
                throw new InvalidArgumentException('Resource ['.static::class."] has no configuration [{$key}] in this panel.");
            }
        }

        $previous = self::$activeConfigurations[static::class] ?? null;
        self::$activeConfigurations[static::class] = $configuration;

        try {
            return $callback();
        } finally {
            self::$activeConfigurations[static::class] = $previous;
        }
    }

    // ---------------------------------------------------------------- Definition hooks

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return $table;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [];
    }

    /**
     * Relation managers shown on edit and view pages; a `RelationGroup` shows several
     * under one tab.
     *
     * @return array<class-string<RelationManager>|RelationManagers\RelationGroup>
     */
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<class-string<Widget>>
     */
    public static function getWidgets(): array
    {
        return [];
    }

    // ---------------------------------------------------------------- Model

    /**
     * @return class-string<Model>
     */
    public static function getModel(): string
    {
        return static::$model ?? (string) Str::of(class_basename(static::class))
            ->beforeLast('Resource')
            ->prepend('App\\Models\\');
    }

    public static function getModelLabel(): string
    {
        return static::$modelLabel ?? ModelLabel::singular(static::getModel());
    }

    public static function getPluralModelLabel(): string
    {
        return static::$pluralModelLabel
            ?? (static::$modelLabel !== null ? ModelLabel::pluralizeOverride(static::$modelLabel) : ModelLabel::plural(static::getModel()));
    }

    public static function hasTitleCaseModelLabel(): bool
    {
        return static::$hasTitleCaseModelLabel;
    }

    public static function getTitleCaseModelLabel(): string
    {
        return static::hasTitleCaseModelLabel() ? Str::ucfirst(static::getModelLabel()) : static::getModelLabel();
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return static::hasTitleCaseModelLabel() ? Str::ucfirst(static::getPluralModelLabel()) : static::getPluralModelLabel();
    }

    public static function getSlug(): string
    {
        // Built from the class name in English, never from the (translatable) label, so
        // URLs stay the same in every language.
        $slug = static::$slug ?? (self::$guessedSlugs[static::getModel()] ??= Str::slug(Labels::pluralize(ModelLabel::guess(static::getModel()), 'en')));
        $configuration = static::getConfiguration();

        // A configured registration lives at its own `slug()`, else under the resource's.
        return $configuration === null ? $slug : ($configuration->getSlug() ?? $slug.'/'.$configuration->getKey());
    }

    public static function getRecordTitleAttribute(): ?string
    {
        return static::$recordTitleAttribute;
    }

    /**
     * Whether records have a title of their own (a `$recordTitleAttribute` or an overridden
     * `getRecordTitle()`), rather than the model label every record shares. Lists that must
     * tell records apart (trees, kanban cards, search results) use it.
     */
    public static function hasRecordTitle(): bool
    {
        return static::getRecordTitleAttribute() !== null
            || (new ReflectionMethod(static::class, 'getRecordTitle'))->getDeclaringClass()->getName() !== self::class;
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $attribute = static::getRecordTitleAttribute();
        $title = $attribute !== null ? data_get($record, $attribute) : null;

        // Without a title attribute (or value), the record is named by the model label, as in
        // Filament: "Edit Post", "Delete Post", never by its key.
        return filled($title) && is_scalar($title) ? (string) $title : Str::ucfirst(static::getModelLabel());
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return static::$recordRouteKeyName;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = static::getModel()::query();

        $panel = alfakit()->getCurrentPanel();

        if ($panel !== null && static::isScopedToTenant() && ($tenant = $panel->getTenant()) !== null) {
            static::scopeEloquentQueryToTenant($query, $tenant);
        }

        // `scopeToUser()`: only the signed-in user's own records (none for guests).
        if (static::getUserOwnership() !== null) {
            static::scopeEloquentQueryToUser($query, ($panel ?? alfakit()->getCurrentOrDefaultPanel())->auth()->user());
        }

        // Nested resources only see the records of the parent record being served.
        static::scopeEloquentQueryToParent($query);

        return $query;
    }

    /**
     * The query the `{record}` route parameter is resolved with: the Eloquent query by default.
     * Override it to widen what pages can open, e.g. `->withoutGlobalScopes([SoftDeletingScope::class])`.
     *
     * @return Builder<Model>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function resolveRecordRouteBinding(mixed $key): ?Model
    {
        $query = static::getRecordRouteBindingEloquentQuery();

        if (in_array(SoftDeletes::class, class_uses_recursive(static::getModel()), true)) {
            $query->withTrashed();
        }

        return $query->where(static::getRecordRouteKeyName() ?? $query->getModel()->getRouteKeyName(), $key)->first();
    }

    // ---------------------------------------------------------------- Tenancy

    public static function isScopedToTenant(): bool
    {
        return static::$isScopedToTenant;
    }

    public static function scopeToTenant(bool $condition = true): void
    {
        static::$isScopedToTenant = $condition;
    }

    public static function getTenantOwnershipRelationshipName(): string
    {
        return static::$tenantOwnershipRelationshipName
            ?? alfakit()->getCurrentOrDefaultPanel()->getTenantOwnershipRelationshipName();
    }

    /**
     * The relationship on the tenant model that holds this resource's records, when
     * `$tenantRelationshipName` is set (`Team::posts()`): new records are created through
     * it. Null without the property (records are associated through the ownership
     * relationship instead).
     */
    public static function getTenantRelationship(Model $tenant): ?\Illuminate\Database\Eloquent\Relations\Relation
    {
        $name = static::$tenantRelationshipName;

        if ($name === null || ! method_exists($tenant, $name)) {
            return null;
        }

        $relationship = $tenant->{$name}();

        return $relationship instanceof \Illuminate\Database\Eloquent\Relations\Relation ? $relationship : null;
    }

    public static function getTenantRelationshipName(): string
    {
        return static::$tenantRelationshipName ?? Str::camel(Labels::pluralize(ModelLabel::guess(static::getModel()), 'en'));
    }

    /**
     * Whether a tenant needs an active subscription to open this resource's pages; by
     * default the panel's `requiresTenantSubscription()`.
     */
    public static function isTenantSubscriptionRequired(Panel $panel): bool
    {
        return $panel->isTenantSubscriptionRequired();
    }

    public static function scopeEloquentQueryToTenant(Builder $query, Model $tenant): Builder
    {
        $relationshipName = static::getTenantOwnershipRelationshipName();
        $relationship = $query->getModel()->{$relationshipName}();

        if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            return $query->where($relationship->getQualifiedForeignKeyName(), $tenant->getKey());
        }

        return $query->whereHas($relationshipName, static fn (Builder $q) => $q->whereKey($tenant->getKey()));
    }

    // ---------------------------------------------------------------- User scoping

    /**
     * Only show the signed-in user's own records (customer portals): `$ownership` is a
     * column (`customer_id`) or a relationship of the model (`customer`, `members`). The
     * owner key is the user's id, or what `$ownerKey($user)` returns. Pass `false` to opt
     * this resource out of the panel's `scopeToUser()`, `null` to follow it again.
     *
     * @param  (Closure(Authenticatable): mixed)|null  $ownerKey
     */
    public static function scopeToUser(string|false|null $ownership = 'user_id', ?Closure $ownerKey = null): void
    {
        self::$userOwnerships[static::class] = [$ownership, $ownerKey];
    }

    /**
     * The ownership column or relationship the records are scoped by, or null when they
     * are not: the registration's (`Resource::make('key')->scopeToUser()`), the class's,
     * then the current panel's.
     */
    public static function getUserOwnership(): ?string
    {
        $configured = static::getConfiguration()?->getUserOwnership();

        if ($configured !== null) {
            return $configured ?: null;
        }

        $ownership = array_key_exists(static::class, self::$userOwnerships) ? self::$userOwnerships[static::class][0] : static::$userOwnership;

        if ($ownership === false) {
            return null;
        }

        return $ownership ?? alfakit()->getCurrentPanel()?->getUserOwnership();
    }

    /**
     * The owner key of the user's records: the user's id unless a resolver was given.
     */
    public static function getUserOwnerKey(Authenticatable $user): mixed
    {
        $resolver = static::getConfiguration()?->getUserOwnerKeyResolver()
            ?? (self::$userOwnerships[static::class][1] ?? null)
            ?? alfakit()->getCurrentPanel()?->getUserOwnerKeyResolver();

        return $resolver !== null ? $resolver($user) : $user->getAuthIdentifier();
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function scopeEloquentQueryToUser(Builder $query, ?Authenticatable $user): Builder
    {
        $ownership = static::getUserOwnership();

        if ($ownership === null) {
            return $query;
        }

        $key = $user === null ? null : static::getUserOwnerKey($user);

        // Fail closed: no user, or a user without an owner record, sees nothing.
        if ($key === null) {
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();

        if (method_exists($model, $ownership)) {
            $relationship = $model->{$ownership}();

            if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                return $query->where($relationship->getQualifiedForeignKeyName(), $key);
            }

            if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                return $query->whereHas($ownership, static fn (Builder $related) => $related->whereKey($key));
            }
        }

        return $query->where($model->qualifyColumn($ownership), $key);
    }

    /**
     * Fill the owner of a new record when the resource is scoped to the user (a column or a
     * `BelongsTo` relationship; other relationships are left to the page).
     */
    public static function associateRecordWithUser(Model $record): void
    {
        $ownership = static::getUserOwnership();
        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();

        if ($ownership === null || $user === null) {
            return;
        }

        $key = static::getUserOwnerKey($user);

        if ($key === null) {
            return;
        }

        if (method_exists($record, $ownership)) {
            $relationship = $record->{$ownership}();

            if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                $record->setAttribute($relationship->getForeignKeyName(), $key);
            }

            return;
        }

        $record->setAttribute($ownership, $key);
    }

    /**
     * The attributes that hold a record's owner when the resource is scoped to the user
     * (`scopeToUser()`): the column, or a `BelongsTo` relationship's foreign key (and morph
     * type). Restoring a version or saving a form never changes them in a scoped panel.
     *
     * @return list<string>
     */
    public static function getUserOwnerAttributes(Model $record): array
    {
        $ownership = static::getUserOwnership();

        if ($ownership === null) {
            return [];
        }

        if (method_exists($record, $ownership)) {
            $relationship = $record->{$ownership}();

            if ($relationship instanceof \Illuminate\Database\Eloquent\Relations\MorphTo) {
                return [$relationship->getForeignKeyName(), $relationship->getMorphType()];
            }

            return $relationship instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo ? [$relationship->getForeignKeyName()] : [];
        }

        return [$ownership];
    }

    // ---------------------------------------------------------------- Authorisation

    public static function can(string $action, ?Model $record = null): bool
    {
        if (static::$shouldSkipAuthorization) {
            return true;
        }

        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();
        $policy = \AlfaSix\Alfakit\Support\Authorization::policyFor(static::getModel());

        // A record ability (`view`, `update`, `delete`, …) without a record (a record action
        // that has none yet) denies instead of calling `view(User $user, Post $post)` with
        // the model class, which would throw.
        if ($record === null && $policy !== null && \AlfaSix\Alfakit\Support\Authorization::requiresRecord($policy, $action)) {
            return false;
        }

        if (! static::shouldCheckPolicyExistence()) {
            return Gate::forUser($user)->check($action, $record ?? static::getModel());
        }

        // Without a policy (method) everything is allowed, unless the panel uses
        // `strictAuthorization()`. Guests (panels with `guestAccess()`) may only look:
        // writing without a policy needs a signed-in user.
        if ($policy === null || ! method_exists($policy, $action)) {
            if ($user === null && ! in_array($action, ['viewAny', 'view'], true)) {
                return false;
            }

            return ! \AlfaSix\Alfakit\Support\Authorization::isStrict();
        }

        return Gate::forUser($user)->check($action, $record ?? static::getModel());
    }

    public static function shouldCheckPolicyExistence(): bool
    {
        return static::$shouldCheckPolicyExistence;
    }

    /**
     * The check prebuilt actions, tables and undo run for an ability: the matching
     * `can*()` method (so overriding `canCreate()`, `canEdit()`, `canView()`, `canDelete()`
     * or `canDeleteAny()` covers them too, as in Filament), else `can()`.
     */
    public static function authorizes(string $ability, ?Model $record = null): bool
    {
        return match (true) {
            $ability === 'viewAny' => static::canViewAny(),
            $ability === 'create' => static::canCreate(),
            $ability === 'deleteAny' => static::canDeleteAny(),
            $ability === 'update' && $record !== null => static::canEdit($record),
            $ability === 'view' && $record !== null => static::canView($record),
            $ability === 'delete' && $record !== null => static::canDelete($record),
            default => static::can($ability, $record),
        };
    }

    public static function canViewAny(): bool
    {
        return static::can('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::can('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::can('update', $record);
    }

    public static function canView(Model $record): bool
    {
        return static::can('view', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::can('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::can('deleteAny');
    }

    public static function canAccess(): bool
    {
        return static::canViewAny();
    }

    // ---------------------------------------------------------------- Routing

    public static function getRouteBaseName(): string
    {
        $parent = static::getParentResource();

        // A nested slug (`cms/pages`) becomes dotted segments: route names with "/" break
        // tools that turn them into code, such as the starter kits' Wayfinder.
        $slug = str_replace('/', '.', trim(static::getSlug(), '/'));

        return $parent === null ? 'resources.'.$slug : $parent::getRouteBaseName().'.'.$slug;
    }

    public static function registerRoutes(Panel $panel): void
    {
        $configuration = static::getConfiguration();

        Route::prefix(static::getRoutePrefix())
            ->name(static::getRouteBaseName().'.')
            ->group(static function () use ($panel, $configuration): void {
                foreach (static::getPages() as $name => $registration) {
                    $route = $registration->register($panel, $name);

                    // The page activates its registration from this route default.
                    if ($configuration !== null) {
                        $route->defaults(ResourceConfiguration::ROUTE_PARAMETER, $configuration->getKey());
                    }
                }
            });
    }

    public static function hasPage(string $name): bool
    {
        return array_key_exists($name, static::getPages());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  string|null  $configuration  A registration key (`make($key)`) to link to; by default the current one
     */
    public static function getUrl(string $name = 'index', array $parameters = [], bool $isAbsolute = true, ?Panel $panel = null, ?Model $tenant = null, ?string $configuration = null): string
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();

        if ($configuration !== null) {
            return static::withConfiguration($configuration, static fn (): string => static::getUrl($name, $parameters, $isAbsolute, $panel, $tenant), $panel);
        }

        // A nested resource without its own index lists its records on the parent's page.
        if ($name === 'index' && ! static::hasPage('index') && static::isNested() && ($url = static::getParentRelationPageUrl()) !== null) {
            return $url;
        }

        if ($tenant !== null) {
            $parameters['tenant'] = $tenant;
        }

        $parameters = static::fillParentRouteParameters($parameters);

        if (isset($parameters['record']) && $parameters['record'] instanceof Model) {
            $parameters['record'] = $parameters['record']->getAttribute(static::getRecordRouteKeyName() ?? $parameters['record']->getRouteKeyName());
        }

        // A table row asks for the same URL twice (the row link and the edit action):
        // remember URLs built from scalar parameters for the rest of the request.
        $scalar = array_filter($parameters, static fn (mixed $value): bool => is_scalar($value) || $value === null) === $parameters;

        if (! $scalar || ! app()->bound('request')) {
            return $panel->route(static::getRouteBaseName().'.'.$name, $parameters, $isAbsolute);
        }

        $request = app('request');
        self::$urlCache ??= new WeakMap;
        $cache = self::$urlCache[$request] ?? [];
        $key = spl_object_id($panel).'|'.static::class.'|'.static::getConfiguration()?->getKey().'|'.$name.'|'.($isAbsolute ? 1 : 0).'|'.serialize($parameters);

        if (! isset($cache[$key])) {
            if (count($cache) >= 2048) {
                $cache = [];
            }

            $cache[$key] = $panel->route(static::getRouteBaseName().'.'.$name, $parameters, $isAbsolute);
            self::$urlCache[$request] = $cache;
        }

        return $cache[$key];
    }

    // ---------------------------------------------------------------- Navigation

    /**
     * A string, or `Htmlable` markup for the navigation item (sanitised; titles use its text).
     */
    public static function getNavigationLabel(): string|Htmlable
    {
        $label = static::getNavigationOverride('label');

        return is_string($label) ? $label : (static::$navigationLabel ?? static::getTitleCasePluralModelLabel());
    }

    public static function getNavigationIcon(): ?string
    {
        return static::hasNavigationOverride('icon') ? static::getNavigationIconOverride() : static::$navigationIcon;
    }

    public static function getActiveNavigationIcon(): ?string
    {
        return static::$activeNavigationIcon ?? static::getNavigationIcon();
    }

    /**
     * A string, or an enum case (`HasLabel`, `HasIcon`): override this method to return
     * one, since the `$navigationGroup` property is typed `?string`.
     */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        if (static::hasNavigationOverride('group')) {
            $group = static::getNavigationOverride('group');

            return $group instanceof UnitEnum || is_string($group) ? $group : null;
        }

        return static::$navigationGroup;
    }

    public static function getNavigationParentItem(): ?string
    {
        if (static::hasNavigationOverride('parentItem')) {
            $parent = static::getNavigationOverride('parentItem');

            return is_string($parent) ? $parent : null;
        }

        return static::$navigationParentItem;
    }

    public static function getNavigationSort(): ?int
    {
        if (static::hasNavigationOverride('sort')) {
            $sort = static::getNavigationOverride('sort');

            return is_int($sort) ? $sort : null;
        }

        return static::$navigationSort;
    }

    public static function getNavigationBadge(): string|int|null
    {
        return null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return static::$navigationBadgeTooltip;
    }

    public static function shouldRegisterNavigation(): bool
    {
        // A nested resource's pages need a parent record: link to them from the parent.
        return static::$shouldRegisterNavigation && ! static::isNested();
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        // Clustered resources are listed in their cluster's sub-navigation instead.
        if (static::getCluster() !== null) {
            return [];
        }

        return static::buildNavigationItems();
    }

    /**
     * The resource's navigation item(s), wherever they are shown (main or cluster navigation).
     *
     * @return array<NavigationItem>
     */
    public static function buildNavigationItems(): array
    {
        // Nested resources are reached from their parent record, not the navigation.
        if (! static::shouldRegisterNavigation() || static::isNested() || ! static::canAccess() || ! static::hasPage('index')) {
            return [];
        }

        // Read now: configured registrations build their items one after another.
        $routeBaseName = static::getRouteBaseName();

        $item = NavigationItem::make(static::getNavigationLabel())
            // Other items can name this one as their parent by class.
            ->key(static::class)
            ->group(static::getNavigationGroup())
            ->parentItem(static::getNavigationParentItem())
            ->icon(static::getNavigationIcon())
            ->activeIcon(static::getActiveNavigationIcon())
            ->isActiveWhen(fn (): bool => request()->routeIs(alfakit()->getCurrentOrDefaultPanel()->generateRouteName($routeBaseName).'.*'))
            ->sort(static::getNavigationSort())
            ->url(static::getUrl());

        // `Panel::deferNavigationBadges()`: the badge (often a count query) loads after the page.
        return [NavigationState::applyBadge($item, static::class, self::class, static fn (): array => [static::getNavigationBadge(), static::getNavigationBadgeColor(), static::getNavigationBadgeTooltip()])];
    }

    /**
     * Navigation shown on the resource's record pages (edit, view, custom record pages),
     * usually `$page->generateNavigationItems([ViewUser::class, EditUser::class])`.
     *
     * @return array<NavigationItem|NavigationGroup>
     */
    public static function getRecordSubNavigation(Pages\Page $page): array
    {
        return [];
    }

    public static function getSubNavigationPosition(): ?SubNavigationPosition
    {
        return static::$subNavigationPosition;
    }

    // ---------------------------------------------------------------- Global search

    public static function canGloballySearch(): bool
    {
        return static::isGloballySearchable() && static::getGloballySearchableAttributes() !== [] && static::canAccess();
    }

    /**
     * `$isGloballySearchable`; with the panel's `globalSearchResourceOptIn()`, only
     * resources that set it to `true` themselves are searchable.
     */
    public static function isGloballySearchable(): bool
    {
        if (! static::$isGloballySearchable) {
            return false;
        }

        if (! alfakit()->getCurrentOrDefaultPanel()->isGlobalSearchResourceOptIn()) {
            return true;
        }

        return (new ReflectionProperty(static::class, 'isGloballySearchable'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * Whether visits of this resource's records are listed in the command palette.
     */
    public static function hasRecentRecords(): bool
    {
        return static::$hasRecentRecords;
    }

    /**
     * Whether the edit and view pages offer the record's history (`$hasVersions`, by default
     * when the model uses `HasRecordVersions`).
     */
    public static function hasVersions(): bool
    {
        return static::$hasVersions ?? in_array(\AlfaSix\Alfakit\Versions\HasRecordVersions::class, class_uses_recursive(static::getModel()), true);
    }

    public static function getGlobalSearchSort(): ?int
    {
        return static::$globalSearchSort;
    }

    public static function shouldSplitGlobalSearchTerms(): bool
    {
        return static::$shouldSplitGlobalSearchTerms ?? true;
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        $attribute = static::getRecordTitleAttribute();

        return $attribute === null ? [] : [$attribute];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return (string) static::getRecordTitle($record);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        if (static::hasPage('edit') && static::canEdit($record)) {
            return static::getUrl('edit', ['record' => $record]);
        }

        if (static::hasPage('view') && static::canView($record)) {
            return static::getUrl('view', ['record' => $record]);
        }

        return null;
    }

    /**
     * Buttons shown next to a global search result. Only actions with a URL are shown
     * (they navigate; nothing runs on the server from the search box).
     *
     * @return array<\AlfaSix\Alfakit\Actions\Action>
     */
    public static function getGlobalSearchResultActions(Model $record): array
    {
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function serializeGlobalSearchResultActions(Model $record): array
    {
        $actions = [];

        foreach (static::getGlobalSearchResultActions($record) as $action) {
            $action->record($record);

            if ($action->isVisible() && $action->getUrl() !== null) {
                $actions[] = $action->toSchema();
            }
        }

        return $actions;
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return static::getEloquentQuery();
    }

    public static function getGlobalSearchResultsLimit(): int
    {
        return static::$globalSearchResultsLimit;
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public static function getGlobalSearchResults(string $search): Collection
    {
        $query = static::getGlobalSearchEloquentQuery();

        $terms = static::shouldSplitGlobalSearchTerms() ? (preg_split('/\s+/', trim($search)) ?: []) : [trim($search)];

        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }

            $query->where(static function (Builder $query) use ($term): void {
                foreach (static::getGloballySearchableAttributes() as $attribute) {
                    if (str_contains($attribute, '.')) {
                        $query->orWhereHas(Str::beforeLast($attribute, '.'), static fn (Builder $q) => $q->where($q->qualifyColumn(Str::afterLast($attribute, '.')), 'like', "%{$term}%"));
                    } else {
                        $query->orWhere($query->qualifyColumn($attribute), 'like', "%{$term}%");
                    }
                }
            });
        }

        return $query->limit(static::getGlobalSearchResultsLimit())->get()
            ->map(static fn (Model $record): ?GlobalSearchResult => static::getGlobalSearchResultUrl($record) === null ? null : new GlobalSearchResult(
                title: static::getGlobalSearchResultTitle($record),
                url: (string) static::getGlobalSearchResultUrl($record),
                details: static::getGlobalSearchResultDetails($record),
                actions: static::serializeGlobalSearchResultActions($record),
            ))
            ->filter()
            ->values();
    }

    public static function isDiscovered(): bool
    {
        return static::$isDiscovered;
    }
}
