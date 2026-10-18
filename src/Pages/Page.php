<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Clusters\Concerns\BelongsToCluster;
use AlfaSix\Alfakit\Navigation\Concerns\HasNavigationSetters;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Navigation\NavigationState;
use AlfaSix\Alfakit\Pages\Concerns\HasActions;
use AlfaSix\Alfakit\Pages\Concerns\HasDefaultAction;
use AlfaSix\Alfakit\Pages\Concerns\HasErrorNotifications;
use AlfaSix\Alfakit\Pages\Concerns\HasExposedMethods;
use AlfaSix\Alfakit\Pages\Concerns\HasSchemaBlocks;
use AlfaSix\Alfakit\Pages\Concerns\HasSubNavigation;
use AlfaSix\Alfakit\Pages\Concerns\HasWidgets;
use AlfaSix\Alfakit\Pages\Concerns\InteractsWithSchemas;
use AlfaSix\Alfakit\Pages\Concerns\InteractsWithTable;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Schemas\DeferredLoading;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Debug\ClosureRecorder;
use AlfaSix\Alfakit\Support\Debug\SchemaDebugger;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\SchemaContext;
use AlfaSix\Alfakit\Support\Serializable;
use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Support\Header;
use UnitEnum;

/**
 * A page inside a panel. Pages are invokable controllers: the router instantiates them,
 * `mount()` receives route parameters, and `render()` returns the Inertia response whose
 * `page` prop describes everything the renderer needs.
 *
 * @method static PageConfiguration make(string $key) Register the page again under `$key` (configurable pages)
 */
abstract class Page
{
    use BelongsToCluster;
    use HasActions;
    use HasDefaultAction;
    use HasErrorNotifications;
    use HasExposedMethods;
    use HasNavigationSetters;
    use HasSchemaBlocks;
    use HasSubNavigation;
    use HasWidgets;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Inertia prop that carries polled components (see `getPolledComponents()`). */
    public const POLL_PROP = 'alfakitPoll';

    /** Request header naming the components a poll refreshes, comma separated. */
    public const POLL_HEADER = 'X-Alfakit-Poll';

    /** Deferred Inertia prop (and defer group) that carries lazy components, keyed by poll target. */
    public const LAZY_PROP = 'alfakitLazy';

    /**
     * Inertia prop with the resolved schema tree, closures and timings for the developer
     * debug panel. Only present while `Panel::hasDebugPanel()` is true (never in production).
     */
    public const DEBUG_PROP = 'alfakitDebug';

    /**
     * Inertia prop with the schema inspector's data (context identity, class links). Only
     * present while `Panel::hasDevTools()` is true (never with `app.debug` off).
     */
    public const DEV_TOOLS_PROP = 'alfakitDevTools';

    protected static ?string $slug = null;

    protected static ?string $routePath = null;

    protected static ?string $title = null;

    /**
     * The page's breadcrumb. On a custom page it is the whole trail (after the cluster's);
     * resource pages show it after the resource's breadcrumbs.
     */
    protected static ?string $breadcrumb = null;

    protected static ?string $navigationIcon = null;

    protected static ?string $activeNavigationIcon = null;

    protected static ?string $navigationLabel = null;

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationParentItem = null;

    protected static ?int $navigationSort = null;

    /** Shown when hovering the navigation badge (`getNavigationBadge()`). */
    protected static ?string $navigationBadgeTooltip = null;

    protected static bool $shouldRegisterNavigation = true;

    /**
     * Run this page's actions (form saves included) in a database transaction: `true`,
     * `false`, or null to follow the panel's `databaseTransactions()`. An action's own
     * `databaseTransaction()` wins.
     */
    protected ?bool $hasDatabaseTransactions = null;

    /** `false` keeps the page out of `discoverPages()` (register it by hand instead). */
    protected static bool $isDiscovered = true;

    /**
     * `app`, `auth`, `blank` or `fullscreen` (the panel without sidebar and topbar, for
     * kiosks, points of sale and other app screens; auth, tenancy, notifications, modals
     * and the debug panel stay).
     */
    protected static string $layout = 'app';

    /** The slim header (brand, title, exit link, user menu) of the `fullscreen` layout. */
    protected static bool $hasFullscreenHeader = true;

    /**
     * Whether guests may open the page when the panel has `guestAccess()`. Authorisation
     * (`canAccess()`, policies) then runs with a `null` user.
     */
    protected static bool $isGuestAccessible = false;

    /** A custom Inertia component to render instead of the schema-driven page. */
    protected static ?string $component = null;

    protected static Width|string|null $maxContentWidth = null;

    /** @var array<string, mixed> */
    protected array $routeParameters = [];

    protected ?string $heading = null;

    protected ?string $subheading = null;

    /** Where the header actions sit in the header: `Alignment::Start`, `Center` or `End` (default). */
    protected ?Alignment $headerActionsAlignment = null;

    /**
     * HTML attributes added to `<body>` while the page is open (`class` is merged with the
     * body's own classes and removed again when the user leaves the page).
     *
     * @var array<string, scalar|null>
     */
    protected array $extraBodyAttributes = [];

    // ---------------------------------------------------------------- Configurations

    /**
     * The options class of `make()` registrations (configurable pages); defaults to
     * `PageConfiguration`.
     *
     * @var class-string<PageConfiguration>|null
     */
    protected static ?string $configurationClass = null;

    /**
     * The registration serving the current request, by page class.
     *
     * @var array<class-string, PageConfiguration|null>
     */
    private static array $activePageConfigurations = [];

    /**
     * Register this page again under `$key` with its own options:
     * `$panel->pages([SettingsPage::make('general')->slug('general-settings')])`.
     * (`make()` is resolved through `__callStatic()`, so subclasses such as standalone
     * components keep their own `make()`.)
     */
    public static function makeConfiguration(string $key): PageConfiguration
    {
        $class = static::$configurationClass ?? PageConfiguration::class;

        return $class::make(static::class, $key);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if ($method === 'make' && isset($arguments[0]) && is_string($arguments[0])) {
            return static::makeConfiguration($arguments[0]);
        }

        throw new BadMethodCallException(sprintf('Call to undefined method %s::%s()', static::class, $method));
    }

    /**
     * The registration serving the current request, or null for the plain registration.
     */
    public static function getConfiguration(): ?PageConfiguration
    {
        return self::$activePageConfigurations[static::class] ?? null;
    }

    public static function hasConfiguration(): bool
    {
        return static::getConfiguration() !== null;
    }

    public static function setConfiguration(?PageConfiguration $configuration): void
    {
        self::$activePageConfigurations[static::class] = $configuration;
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
    public static function withConfiguration(PageConfiguration|string|null $configuration, Closure $callback, ?Panel $panel = null): mixed
    {
        if (is_string($configuration)) {
            $key = $configuration;
            $configuration = ($panel ?? alfakit()->getCurrentOrDefaultPanel())->getPageConfiguration(static::class, $key);

            abort_if($configuration === null, 404);
        }

        $previous = self::$activePageConfigurations[static::class] ?? null;
        self::$activePageConfigurations[static::class] = $configuration;

        try {
            return $callback();
        } finally {
            self::$activePageConfigurations[static::class] = $previous;
        }
    }

    /**
     * Make the registration named by a route parameter / context token active (404 for an
     * unknown key).
     */
    protected static function activatePageConfiguration(mixed $key): void
    {
        $configuration = is_string($key) ? alfakit()->getCurrentOrDefaultPanel()->getPageConfiguration(static::class, $key) : null;

        abort_if(is_string($key) && $configuration === null, 404);

        static::setConfiguration($configuration);
    }

    // ---------------------------------------------------------------- Static configuration

    public static function getSlug(): string
    {
        $slug = static::$slug ?? Str::of(class_basename(static::class))->kebab()->slug()->toString();
        $configuration = static::getConfiguration();

        // A configured registration lives at its own `slug()`, else under the page's.
        return $configuration === null ? $slug : ($configuration->getSlug() ?? $slug.'/'.$configuration->getKey());
    }

    public static function getRoutePath(): string
    {
        return static::hasConfiguration() ? '/'.static::getSlug() : (static::$routePath ?? '/'.static::getSlug());
    }

    /**
     * The route name relative to the panel prefix.
     */
    public static function getRelativeRouteName(): string
    {
        return 'pages.'.static::getSlug();
    }

    public static function registerRoutes(Panel $panel): void
    {
        // Clustered pages live under the cluster's slug: `/settings/profile`.
        $route = Route::get(static::getClusterRoutePrefix().ltrim(static::getRoutePath(), '/'), static::class)->name(static::getRelativeRouteName());

        if (($configuration = static::getConfiguration()) !== null) {
            // The page activates its registration from this route default.
            $route->defaults(PageConfiguration::ROUTE_PARAMETER, $configuration->getKey());
        }

        if ($panel->hasTenantBilling()) {
            $panel->applyTenantSubscriptionMiddleware($route, static::isTenantSubscriptionRequired($panel));
        }
    }

    /**
     * Whether a tenant needs an active subscription to open this page; by default the
     * panel's `requiresTenantSubscription()`. Override to exempt a page (or require it).
     */
    public static function isTenantSubscriptionRequired(Panel $panel): bool
    {
        return $panel->isTenantSubscriptionRequired();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function getUrl(array $parameters = [], bool $isAbsolute = true, ?Panel $panel = null): string
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();

        return $panel->route(static::getRelativeRouteName(), $parameters, $isAbsolute);
    }

    public static function route(string $path): PageRegistration
    {
        return new PageRegistration(static::class, $path);
    }

    /**
     * A string, or `Htmlable` markup for the navigation item (sanitised; titles and
     * breadcrumbs use its text).
     */
    public static function getNavigationLabel(): string|Htmlable
    {
        $label = static::getNavigationOverride('label');

        return is_string($label) ? $label : (static::$navigationLabel ?? static::$title ?? Labels::fromName(class_basename(static::class)));
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

    public function hasDatabaseTransactions(): ?bool
    {
        return $this->hasDatabaseTransactions;
    }

    public static function isDiscovered(): bool
    {
        return static::$isDiscovered;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::$shouldRegisterNavigation;
    }

    public static function canAccess(): bool
    {
        return true;
    }

    public static function isGuestAccessible(): bool
    {
        return static::$isGuestAccessible;
    }

    /**
     * Whether the current visitor may open the page as far as signing in goes: signed-in
     * users always, guests on guest-accessible pages of a `guestAccess()` panel.
     */
    public static function isAccessibleToCurrentVisitor(): bool
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        return $panel->auth()->check() || ! static::requiresAuthentication() || $panel->isGuestAccessible(static::class, alfakit()->getTenant());
    }

    /**
     * Whether internal endpoints (actions, schema refresh…) for this page require an
     * authenticated user. Guest pages such as Login return false.
     */
    public static function requiresAuthentication(): bool
    {
        return true;
    }

    /**
     * @return array<NavigationItem>
     */
    public static function getNavigationItems(): array
    {
        // Clustered pages are listed in their cluster's sub-navigation instead.
        if (static::getCluster() !== null) {
            return [];
        }

        return static::buildNavigationItems();
    }

    /**
     * The page's navigation item(s), wherever they are shown (main or cluster navigation).
     *
     * @return array<NavigationItem>
     */
    public static function buildNavigationItems(): array
    {
        if (! static::shouldRegisterNavigation() || ! static::isAccessibleToCurrentVisitor() || ! static::canAccess()) {
            return [];
        }

        // Read now: configured registrations build their items one after another.
        $routeName = static::getRelativeRouteName();

        $item = NavigationItem::make(static::getNavigationLabel())
            // Other items can name this one as their parent by class.
            ->key(static::class)
            ->group(static::getNavigationGroup())
            ->parentItem(static::getNavigationParentItem())
            ->icon(static::getNavigationIcon())
            ->activeIcon(static::getActiveNavigationIcon())
            ->isActiveWhen(fn (): bool => request()->routeIs(alfakit()->getCurrentOrDefaultPanel()->generateRouteName($routeName)))
            ->sort(static::getNavigationSort())
            ->url(static::getUrl());

        // `Panel::deferNavigationBadges()`: the badge loads after the page.
        return [NavigationState::applyBadge($item, static::class, self::class, static fn (): array => [static::getNavigationBadge(), static::getNavigationBadgeColor(), static::getNavigationBadgeTooltip()])];
    }

    // ---------------------------------------------------------------- Request lifecycle

    public function __invoke(Request $request): mixed
    {
        // The router memoises controller instances, so a long-lived process (tests,
        // Octane) would otherwise reuse state from a previous request.
        $this->flushCaches();

        // Configurable pages: serve the request as the registration its route belongs to.
        static::activatePageConfiguration($request->route()?->parameter(PageConfiguration::ROUTE_PARAMETER));

        // Start before mount() so the debug panel also sees the closures and queries of
        // loading the record. Never active in production (`app.debug` off).
        if (SchemaDebugger::shouldInclude($this->getPanel(), $request)) {
            SchemaDebugger::start();
        }

        abort_unless(static::canAccess(), 403);

        // Bound models (the tenant, after IdentifyTenant) are stored by route key so the
        // context token never carries serialised models.
        $this->routeParameters = [];

        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            $this->routeParameters[$name] = match (true) {
                $name === 'tenant' && $value instanceof \Illuminate\Database\Eloquent\Model => $this->getPanel()->getTenantRouteKey($value),
                $value instanceof \Illuminate\Database\Eloquent\Model => $value->getRouteKey(),
                default => $value,
            };
        }

        $this->bootRouteParameters();
        $this->mount(...$this->getMountParameters());

        // The form is filled (and `beforeFill`/`afterFill` run) once, when the page loads;
        // internal requests rebuild it from the browser's state instead.
        $this->shouldCallFillHooks = true;

        return $this->render();
    }

    /**
     * Runs before `mount()` with the route parameters set (resolves e.g. the parent record
     * of a nested resource page).
     */
    protected function bootRouteParameters(): void {}

    /**
     * Route parameter names that are not passed to `mount()`.
     *
     * @return array<string>
     */
    protected function getNonMountParameterNames(): array
    {
        return ['tenant', PageConfiguration::ROUTE_PARAMETER];
    }

    /**
     * @return array<int, mixed>
     */
    protected function getMountParameters(): array
    {
        $excluded = $this->getNonMountParameterNames();

        return array_values(array_filter($this->routeParameters, static fn (string $key): bool => ! in_array($key, $excluded, true), ARRAY_FILTER_USE_KEY));
    }

    /**
     * Receives the route parameters (excluding the tenant). Override to load records.
     */
    public function mount(mixed ...$parameters): void {}

    /**
     * Forget everything derived from the previous request.
     */
    protected function flushCaches(): void
    {
        $this->cachedTable = null;
        $this->cachedForm = null;
        $this->cachedInfolist = null;
        $this->cachedActions = null;
        $this->cachedWidgets = null;
        $this->cachedSchemaBlocks = [];
        $this->clientFormStates = [];
        $this->propsToReload = null;
        $this->routeParameters = [];
        $this->shouldCallFillHooks = false;
        $this->errorNotifications = [];

        if (property_exists($this, 'cachedRelationManagers')) {
            $this->cachedRelationManagers = null;
        }

        if (property_exists($this, 'record')) {
            $this->record = null;
        }

        if (property_exists($this, 'refreshedFormData')) {
            $this->refreshedFormData = [];
        }
    }

    /**
     * Rebuild the page for an internal request (action execution, schema refresh…).
     */
    public static function fromContext(SchemaContext $context): static
    {
        static::activatePageConfiguration($context->parameters[PageConfiguration::ROUTE_PARAMETER] ?? null);

        $page = app(static::class);
        $page->routeParameters = $context->parameters;
        $page->bootRouteParameters();
        $page->mount(...$page->getMountParameters());

        return $page;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRouteParameters(): array
    {
        return array_map(static fn (mixed $value): mixed => $value instanceof \Illuminate\Database\Eloquent\Model ? $value->getRouteKey() : $value, $this->routeParameters);
    }

    public function getPanel(): Panel
    {
        return alfakit()->getCurrentOrDefaultPanel();
    }

    public function getContextToken(?string $component = null): string
    {
        return (new SchemaContext($this->getPanel()->getId(), static::class, $this->getRouteParameters(), $component))->toToken();
    }

    // ---------------------------------------------------------------- Content

    public function getTitle(): string
    {
        return static::$title ?? Html::toText(static::getNavigationLabel());
    }

    public function getHeading(): ?string
    {
        return $this->heading ?? $this->getTitle();
    }

    public function getSubheading(): ?string
    {
        return $this->subheading;
    }

    /**
     * @return array<int|string, string> `url => label` pairs; the last item may use an int key.
     */
    public function getBreadcrumbs(): array
    {
        $breadcrumb = $this->getBreadcrumb();

        return $breadcrumb === null || $breadcrumb === '' ? [] : [$breadcrumb];
    }

    /**
     * `$breadcrumb`: the label of this page in the breadcrumbs (none by default on custom pages).
     */
    public function getBreadcrumb(): ?string
    {
        return static::$breadcrumb;
    }

    public static function getLayout(): string
    {
        return static::$layout;
    }

    public static function hasFullscreenHeader(): bool
    {
        return static::$hasFullscreenHeader;
    }

    /**
     * Where the `fullscreen` layout's exit link goes: the panel's home (of the current tenant).
     */
    public function getExitUrl(): ?string
    {
        return $this->getPanel()->getUrl(alfakit()->getTenant());
    }

    public function getHeaderActionsAlignment(): ?Alignment
    {
        return $this->headerActionsAlignment;
    }

    /**
     * Attributes for `<body>` while this page is open. Only plain values are kept, and
     * event handler attributes (`on*`) are dropped.
     *
     * @return array<string, scalar|null>
     */
    public function getExtraBodyAttributes(): array
    {
        return $this->extraBodyAttributes;
    }

    /**
     * @return array<string, string>
     */
    protected function getExtraBodyAttributesSchema(): array
    {
        $attributes = [];

        foreach ($this->getExtraBodyAttributes() as $name => $value) {
            if (! is_string($name) || preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name) !== 1 || str_starts_with(strtolower($name), 'on')) {
                continue;
            }

            if ($value === null || $value === false) {
                continue;
            }

            $attributes[$name] = $value === true ? '' : (string) $value;
        }

        return $attributes;
    }

    public function getMaxContentWidth(): ?string
    {
        $width = static::$maxContentWidth;

        return $width instanceof Width ? $width->value : $width;
    }

    /**
     * Schema-driven content for custom pages (sections, text, embedded views…).
     */
    public function content(Schema $schema): Schema
    {
        return $schema;
    }

    protected function getContentSchema(): ?Schema
    {
        $schema = $this->content($this->makeSchema());

        return $schema->getComponents(withHidden: true) === [] ? null : $schema;
    }

    /**
     * The ordered blocks rendered in the page body. Override in page types.
     *
     * @return array<array<string, mixed>>
     */
    protected function getContentBlocks(): array
    {
        $blocks = [];

        if (($form = $this->getForm()) !== null) {
            $blocks[] = $this->serializeForm($form);
        }

        if (($infolist = $this->getInfolist()) !== null) {
            $blocks[] = $this->serializeInfolist($infolist);
        }

        if (($table = $this->getTable()) !== null) {
            $blocks[] = $this->serializeTable($table);
        }

        if (($content = $this->getContentSchema()) !== null) {
            $blocks[] = ['type' => 'Schema', 'key' => 'content', 'components' => $content->toSchema()];
        }

        // Named pieces (`customerForm()`, `productsTable()`…) for custom components.
        return [...$blocks, ...$this->serializeSchemaBlocks()];
    }

    /**
     * Extra props merged into the Inertia page (custom pages).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        $header = $this->serializeWidgets($this->getHeaderWidgets(), $this->getHeaderWidgetsColumns(), 'headerWidgets');
        $footer = $this->serializeWidgets($this->getFooterWidgets(), $this->getFooterWidgetsColumns(), 'footerWidgets');

        $breadcrumbs = [];

        foreach ($this->getBreadcrumbsWithCluster() as $url => $label) {
            $safe = is_string($url) ? \AlfaSix\Alfakit\Support\Url::sanitize($url) : null;
            $breadcrumbs[] = $safe !== null ? ['label' => $label, 'url' => $safe] : ['label' => $label];
        }

        return Serializable::compact([
            'class' => static::class,
            'layout' => static::getLayout(),
            'title' => $this->getTitle(),
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'breadcrumbs' => $breadcrumbs ?: null,
            'maxContentWidth' => $this->getMaxContentWidth(),
            'fullscreen' => static::getLayout() === 'fullscreen' ? ['header' => static::hasFullscreenHeader(), 'exitUrl' => $this->getExitUrl()] : null,
            'subNavigation' => $this->getSubNavigationSchema(),
            'headerActions' => $this->serializeActions($this->getCachedHeaderActions()) ?: null,
            'headerActionsAlignment' => ($alignment = $this->getHeaderActionsAlignment()) === null || $alignment === Alignment::End ? null : $alignment->value,
            'extraBodyAttributes' => $this->getExtraBodyAttributesSchema() ?: null,
            'content' => array_values(array_filter([$header, ...$this->getContentBlocks(), $footer])),
            'token' => $this->getContextToken(),
            'record' => method_exists($this, 'getRecord') ? $this->getRecord()?->getKey() : null,
            'view' => static::$component !== null ? ['component' => static::$component, 'props' => $this->getViewData()] : null,
            // An action modal the renderer opens once the page hydrated (`$defaultAction`,
            // `?defaultAction=`, `?tableAction=`).
            'defaultAction' => $this->getDefaultActionSchema(),
            // Replaces the panel's `errorNotifications` while this page is open (absent when
            // the page follows the panel, false when it turns them off).
            'errorNotifications' => $this->getErrorNotificationsSchema(),
        ]);
    }

    public function render(): InertiaResponse
    {
        $component = static::$component ?? (string) config('alfakit.inertia.page_component', 'Alfakit/Page');

        $lazy = $this->getLazyComponentTargets();
        $debug = SchemaDebugger::shouldInclude($this->getPanel(), request());

        if ($debug && ! ClosureRecorder::$enabled) {
            SchemaDebugger::start();
        }

        return Inertia::render($component, [
            // `deferLoading()` components are placeholders on full page responses only.
            'page' => fn (): array => DeferredLoading::withPlaceholders(fn (): array => $this->toProps(), $this->rendersLazyPlaceholders()),
            // Only sent when a poll asks for it (`only: ['alfakitPoll']`): the fresh schema
            // of the components named in the `X-Alfakit-Poll` header, without the rest of the page.
            self::POLL_PROP => Inertia::optional(fn (): array => $this->getPolledComponents(self::pollTargets(request()))),
            // Lazy widgets and relation managers: placeholders in `page`, schemas loaded by
            // the client right after the first response. Pages without them make no request.
            ...($lazy === [] ? [] : [self::LAZY_PROP => Inertia::defer(fn (): array => $this->getPolledComponents($lazy), self::LAZY_PROP)]),
            ...(static::$component !== null ? $this->getViewData() : []),
            // Last, so it sees every closure the props above evaluated. Absent unless the
            // panel's debug panel is active for this request.
            ...($debug ? [self::DEBUG_PROP => fn (): array => SchemaDebugger::collect($this, request())] : []),
            ...($this->getPanel()->hasDevTools() ? [self::DEV_TOOLS_PROP => fn (): array => \AlfaSix\Alfakit\Support\DevTools\DevTools::collect($this)] : []),
        ]);
    }

    /**
     * Poll targets (`widget:<id>`, `relationManager:Class`) of the lazy components on this
     * page, loaded by the `alfakitLazy` deferred prop.
     *
     * @return array<string>
     */
    public function getLazyComponentTargets(): array
    {
        $targets = [];

        foreach ($this->getAllWidgetInstances() as $id => $widget) {
            if ($widget::isLazy()) {
                $targets[] = "widget:{$id}";
            }
        }

        if (method_exists($this, 'getRelationManagers')) {
            foreach ($this->getRelationManagers() as $manager) {
                if ($manager::isLazy()) {
                    $targets[] = $manager->getIdentifier();
                } elseif ($manager::isBadgeDeferred()) {
                    // `$isBadgeDeferred`: only the tab badge waits for the deferred request.
                    $targets[] = 'relationManagerBadge:'.substr($manager->getIdentifier(), strlen('relationManager:'));
                }
            }
        }

        if (method_exists($this, 'getDeferredBadgeRelationGroups') && method_exists($this, 'getRelationManagers') && $this->getRelationManagers() !== []) {
            foreach (array_keys($this->getDeferredBadgeRelationGroups()) as $key) {
                // `RelationGroup::deferBadge()`.
                $targets[] = 'relationGroupBadge:'.$key;
            }
        }

        return $targets;
    }

    /**
     * Lazy components render as placeholders on full page responses only. Partial reloads
     * (table paging, sorting, filters) render them in place, so they never wait for the
     * deferred request and never show stale data.
     */
    public function rendersLazyPlaceholders(): bool
    {
        return ! request()->hasHeader(Header::PARTIAL_COMPONENT);
    }

    /**
     * Component names a poll request asks for (`table`, `relationManager:Class`, `widget:id`).
     *
     * @return array<string>
     */
    public static function pollTargets(Request $request): array
    {
        $header = (string) $request->header(self::POLL_HEADER, '');
        $targets = array_values(array_unique(array_filter(array_map('trim', explode(',', $header)))));

        return array_slice($targets, 0, 50);
    }

    /**
     * The fresh schema of each polled component, keyed by its poll target. Unknown or hidden
     * components are left out, so the renderer keeps what it has.
     *
     * @param  array<string>  $targets
     * @return array<string, array<string, mixed>>
     */
    public function getPolledComponents(array $targets): array
    {
        $components = [];

        foreach ($targets as $target) {
            $schema = $this->resolvePolledComponent($target);

            if ($schema !== null) {
                $components[$target] = $schema;
            }
        }

        return $components;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolvePolledComponent(string $target): ?array
    {
        if ($target === 'table') {
            $table = $this->getTable();

            return $table === null ? null : $this->serializeTable($table);
        }

        if (str_starts_with($target, 'table:')) {
            $table = $this->getNamedTable(substr($target, strlen('table:')));

            return $table === null ? null : $this->serializeTable($table, $target);
        }

        if (str_starts_with($target, 'widget:')) {
            return $this->getWidgetInstance(substr($target, strlen('widget:')))?->toSchema();
        }

        if (str_starts_with($target, 'relationGroupBadge:') && method_exists($this, 'getDeferredBadgeRelationGroups') && method_exists($this, 'getRecord')) {
            $group = $this->getDeferredBadgeRelationGroups()[substr($target, strlen('relationGroupBadge:'))] ?? null;

            return $group?->getBadgeSchema($this->getRecord());
        }

        if (str_starts_with($target, 'relationManagerBadge:') && method_exists($this, 'getRelationManager')) {
            return $this->getRelationManager(substr($target, strlen('relationManagerBadge:')))?->getBadgeSchema();
        }

        if (str_starts_with($target, 'relationManager:') && method_exists($this, 'getRelationManager')) {
            $manager = $this->getRelationManager(substr($target, strlen('relationManager:')));

            return $manager?->toSchema();
        }

        return null;
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Stop the current operation (create, save, an action) without an error, e.g. after
     * sending a notification from `beforeCreate()`. The form stays as filled in.
     */
    public function halt(): never
    {
        throw new \AlfaSix\Alfakit\Actions\Exceptions\Halt;
    }

    /**
     * Run a lifecycle hook (`beforeSave`…): the page's own method, then the methods traits
     * add for it, named after the hook and the trait (`afterSaveHasAuditLog()` from
     * `HasAuditLog`), like Filament.
     *
     * @param  array<int, mixed>  $arguments
     */
    protected function callHook(string $hook, array $arguments = []): void
    {
        if (method_exists($this, $hook)) {
            $this->{$hook}(...$arguments);
        }

        foreach (class_uses_recursive($this) as $trait) {
            $method = $hook.class_basename($trait);

            if (method_exists($this, $method)) {
                $this->{$method}(...$arguments);
            }
        }
    }

    /**
     * Redirect helper honouring the panel URL structure.
     */
    protected function redirect(string $url): \Illuminate\Http\RedirectResponse
    {
        return redirect()->to($url);
    }

    protected function makeAction(string $name, ?Closure $callback = null): Action
    {
        $action = Action::make($name)->context($this);

        if ($callback !== null) {
            $action->action($callback);
        }

        return $action;
    }
}
