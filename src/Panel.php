<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Auth\Pages\EditProfile;
use AlfaSix\Alfakit\Auth\Pages\EmailVerificationPrompt;
use AlfaSix\Alfakit\Auth\Pages\Login;
use AlfaSix\Alfakit\Auth\Pages\Register;
use AlfaSix\Alfakit\Auth\Pages\RequestPasswordReset;
use AlfaSix\Alfakit\Auth\Pages\ResetPassword;
use AlfaSix\Alfakit\Contracts\HasAvatar;
use AlfaSix\Alfakit\Contracts\HasCurrentTenantLabel;
use AlfaSix\Alfakit\Contracts\HasDefaultTenant;
use AlfaSix\Alfakit\Contracts\HasName;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\Alfakit\Contracts\Plugin;
use AlfaSix\Alfakit\Http\Controllers\ActionController;
use AlfaSix\Alfakit\Http\Controllers\Auth\LogoutController;
use AlfaSix\Alfakit\Http\Controllers\Auth\VerifyEmailController;
use AlfaSix\Alfakit\Http\Controllers\DeferredSchemaController;
use AlfaSix\Alfakit\Http\Controllers\GlobalSearchController;
use AlfaSix\Alfakit\Http\Controllers\ImportExportController;
use AlfaSix\Alfakit\Http\Controllers\LocaleController;
use AlfaSix\Alfakit\Http\Controllers\NotificationsController;
use AlfaSix\Alfakit\Http\Controllers\PageMethodController;
use AlfaSix\Alfakit\Http\Controllers\PanelHomeController;
use AlfaSix\Alfakit\Http\Controllers\SchemaController;
use AlfaSix\Alfakit\Http\Controllers\TableController;
use AlfaSix\Alfakit\Http\Controllers\UploadController;
use AlfaSix\Alfakit\Http\Middleware\Authenticate;
use AlfaSix\Alfakit\Http\Middleware\HandleInertiaRequests;
use AlfaSix\Alfakit\Http\Middleware\IdentifyTenant;
use AlfaSix\Alfakit\Http\Middleware\PreserveFlashData;
use AlfaSix\Alfakit\Http\Middleware\SetPanelLocale;
use AlfaSix\Alfakit\Http\Middleware\SetUpPanel;
use AlfaSix\Alfakit\Localization\Concerns\HasLocalization;
use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Pages\PageConfiguration;
use AlfaSix\Alfakit\Panel\Concerns\HasPanelOptions;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Resources\ResourceConfiguration;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Credit;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\SchemaContract;
use AlfaSix\Alfakit\Support\Serializable;
use AlfaSix\Alfakit\Tenancy\Pages\EditTenantProfile;
use AlfaSix\Alfakit\Tenancy\Pages\RegisterTenant;
use AlfaSix\Alfakit\Theming\Concerns\HasSlots;
use AlfaSix\Alfakit\Theming\Concerns\HasTheme;
use AlfaSix\Alfakit\Theming\ThemeMode;
use AlfaSix\Alfakit\Widgets\Widget;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;
use UnitEnum;

/**
 * A panel is one admin area: its URL prefix, authentication, branding, navigation and
 * the resources, pages and widgets it contains.
 */
class Panel
{
    use Configurable;
    use EvaluatesClosures;
    use HasLocalization;
    use HasPanelOptions;
    use HasSlots;
    use HasTheme;
    use Macroable;
    use Panel\Concerns\HasAuthRouteSlugs;
    use Panel\Concerns\HasChromeOptions;
    use Panel\Concerns\HasColors;
    use Panel\Concerns\HasCommandPalette;
    use Panel\Concerns\HasCustomRoutes;
    use Panel\Concerns\HasDatabaseNotificationsPosition;
    use Panel\Concerns\HasDebugPanel;
    use Panel\Concerns\HasDevTools;
    use Panel\Concerns\HasEmailChangeVerification;
    use Panel\Concerns\HasGuestAccess;
    use Panel\Concerns\HasHtmlSanitizer;
    use Panel\Concerns\HasImpersonation;
    use Panel\Concerns\HasInertiaProps;
    use Panel\Concerns\HasMenuActions;
    use Panel\Concerns\HasMultiFactorAuthentication;
    use Panel\Concerns\HasNavigationPositions;
    use Panel\Concerns\HasPageConfigurations;
    use Panel\Concerns\HasPanelAssets;
    use Panel\Concerns\HasPortal;
    use Panel\Concerns\HasPwa;
    use Panel\Concerns\HasRealtime;
    use Panel\Concerns\HasRealtimeNotifications;
    use Panel\Concerns\HasRelationManagerOptions;
    use Panel\Concerns\HasResourceConfigurations;
    use Panel\Concerns\HasResourceRedirects;
    use Panel\Concerns\HasStarterKit;
    use Panel\Concerns\HasTenantBilling;
    use Panel\Concerns\HasTenantDomain;
    use Panel\Concerns\HasTenantUploads;

    protected string $id;

    protected string $path = '';

    protected ?string $domain = null;

    /** @var array<int, string> */
    protected array $domains = [];

    protected bool $isDefault = false;

    protected string $authGuard = 'web';

    protected ?string $authPasswordBroker = null;

    /** @var array<string|Closure> */
    protected array $middleware = [];

    /** @var array<string|Closure> */
    protected array $authMiddleware = [];

    protected string|Closure|null $brandName = null;

    protected string|Closure|null $brandLogo = null;

    protected string|Closure|null $darkModeBrandLogo = null;

    protected string|Closure|null $brandLogoHeight = null;

    protected string|Closure|null $favicon = null;

    protected bool|Closure $hasDarkMode = true;

    protected bool|Closure $hasDarkModeForced = false;

    protected bool $isSidebarCollapsibleOnDesktop = true;

    protected bool $isSidebarFullyCollapsibleOnDesktop = false;

    protected bool $hasTopNavigation = false;

    protected Width|string|Closure|null $maxContentWidth = null;

    protected bool $hasBreadcrumbs = true;

    protected bool $hasUnsavedChangesAlerts = false;

    protected bool $hasSpaMode = true;

    /** @var array<class-string<resource>> */
    protected array $resources = [];

    /** @var array<class-string<Page>> */
    protected array $pages = [];

    /** @var array<class-string<Widget>> */
    protected array $widgets = [];

    /** @var array<array{directory: string, namespace: string}> */
    protected array $resourceDiscovery = [];

    /** @var array<array{directory: string, namespace: string}> */
    protected array $pageDiscovery = [];

    /** @var array<array{directory: string, namespace: string}> */
    protected array $widgetDiscovery = [];

    /**
     * Registered + discovered components, memoised per panel instance (never shared between
     * panels or application instances) and reset whenever the registration changes.
     *
     * @var array<string, array<class-string>>
     */
    protected array $resolvedComponents = [];

    /** @var array<NavigationItem>|Closure */
    protected array|Closure $navigationItems = [];

    /** @var array<NavigationGroup|string>|Closure */
    protected array|Closure $navigationGroups = [];

    protected ?Closure $navigationBuilder = null;

    protected bool $hasNavigation = true;

    /** @var array<array-key, mixed>|Closure */
    protected array|Closure $userMenuItems = [];

    protected bool $hasUserMenu = true;

    protected bool $hasLogin = false;

    protected ?string $loginPage = null;

    protected bool $hasRegistration = false;

    protected ?string $registrationPage = null;

    protected bool $hasPasswordReset = false;

    protected bool $hasEmailVerification = false;

    protected bool|Closure $hasCredit = true;

    protected string|Closure|null $creditUrl = null;

    protected bool $hasProfile = false;

    protected ?string $profilePage = null;

    protected bool $isProfilePageSimple = true;

    protected bool $hasDatabaseNotifications = false;

    protected string|Closure|null $databaseNotificationsPolling = '30s';

    protected bool $hasGlobalSearch = true;

    /** @var array<string> */
    protected array $globalSearchKeyBindings = ['mod+k'];

    protected string|Closure|null $homeUrl = null;

    /** @var array<Plugin> */
    protected array $plugins = [];

    /** @var array<Closure> */
    protected array $bootCallbacks = [];

    // Tenancy
    /** @var class-string<Model>|null */
    protected ?string $tenantModel = null;

    protected ?string $tenantSlugAttribute = null;

    protected ?string $tenantOwnershipRelationshipName = null;

    /** @var class-string<RegisterTenant>|null */
    protected ?string $tenantRegistrationPage = null;

    /** @var class-string<EditTenantProfile>|null */
    protected ?string $tenantProfilePage = null;

    /** @var array<array-key, mixed>|Closure */
    protected array|Closure $tenantMenuItems = [];

    protected bool $hasTenantMenu = true;

    /** @var array<string|Closure> */
    protected array $tenantMiddleware = [];

    protected string|Closure|null $tenantRoutePrefix = null;

    protected bool $isBooted = false;

    protected ?Closure $userNameUsing = null;

    protected bool $hasCustomizableDashboards = false;

    /** `tableSavedViews()`: every table offers saved views unless it opts out. */
    protected bool $hasTableSavedViews = false;

    /** @var array<class-string<Widget>|Widget> */
    protected array $availableDashboardWidgets = [];

    final public function __construct(string $id = 'admin')
    {
        $this->id = $id;
    }

    public static function make(string $id = 'admin'): static
    {
        $static = new static($id);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'panel';
    }

    /**
     * Request-aware injections for panel closures (theme, design, slots, options):
     * `$user`, `$tenant`, `$page` (the current page class), `$route` and `$request`.
     *
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'user' => [$this->auth()->user()],
            'tenant' => [$this->getTenant()],
            'page' => [$this->getCurrentPageClass()],
            'route' => [app()->bound('request') ? request()->route() : null],
            'request' => [app()->bound('request') ? request() : null],
            default => [],
        };
    }

    /**
     * The page class the current request is for (its route controller), or null outside a
     * page request (internal endpoints, console).
     *
     * @return class-string<Page>|null
     */
    public function getCurrentPageClass(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $controller = request()->route()?->getControllerClass();

        /** @var class-string<Page>|null */
        return is_string($controller) && is_a($controller, Page::class, true) ? $controller : null;
    }

    // ---------------------------------------------------------------- Identity

    public function id(string $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function path(string $path): static
    {
        $this->path = trim($path, '/');

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function domain(?string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain ?? ($this->domains[0] ?? null);
    }

    /**
     * Serve the panel on several domains (`['admin.example.com', 'admin.example.org']`).
     * Links point to the domain of the current request; the first domain is used
     * elsewhere (queued mail, console).
     *
     * @param  array<int, string>  $domains
     */
    public function domains(array $domains): static
    {
        $this->domains = array_values(array_filter($domains, static fn (mixed $domain): bool => is_string($domain) && $domain !== ''));

        return $this;
    }

    /**
     * @return array<int, string|null>
     */
    public function getDomains(): array
    {
        return $this->domains !== [] ? $this->domains : [$this->domain];
    }

    public function default(bool $condition = true): static
    {
        $this->isDefault = $condition;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    // ---------------------------------------------------------------- Auth

    public function authGuard(string $guard): static
    {
        $this->authGuard = $guard;

        return $this;
    }

    public function getAuthGuard(): string
    {
        return $this->authGuard;
    }

    public function auth(): Guard|StatefulGuard
    {
        return Auth::guard($this->authGuard);
    }

    public function authPasswordBroker(?string $broker): static
    {
        $this->authPasswordBroker = $broker;

        return $this;
    }

    public function getAuthPasswordBroker(): ?string
    {
        return $this->authPasswordBroker;
    }

    /**
     * @param  class-string<Page>|bool|null  $page
     */
    public function login(string|bool|null $page = Login::class): static
    {
        $this->hasLogin = $page !== false && $page !== null;
        $this->loginPage = is_string($page) ? $page : Login::class;

        return $this;
    }

    public function hasLogin(): bool
    {
        return $this->hasLogin;
    }

    /**
     * @param  class-string<Page>|bool|null  $page
     */
    public function registration(string|bool|null $page = Register::class): static
    {
        $this->hasRegistration = $page !== false && $page !== null;
        $this->registrationPage = is_string($page) ? $page : Register::class;

        return $this;
    }

    public function hasRegistration(): bool
    {
        return $this->hasRegistration;
    }

    public function passwordReset(bool $condition = true): static
    {
        $this->hasPasswordReset = $condition;

        return $this;
    }

    public function hasPasswordReset(): bool
    {
        return $this->hasPasswordReset;
    }

    /**
     * The "Developed by Alfa Six" line in the panel footer. On by default; pass `false` to
     * remove it, or a closure to decide per request.
     */
    public function credit(bool|Closure $condition = true): static
    {
        $this->hasCredit = $condition;

        return $this;
    }

    public function creditUrl(string|Closure|null $url): static
    {
        $this->creditUrl = $url;

        return $this;
    }

    public function hasCredit(): bool
    {
        return (bool) $this->evaluate($this->hasCredit);
    }

    public function getCreditUrl(): string
    {
        return $this->evaluate($this->creditUrl) ?? 'https://alfasixbh.com';
    }

    /**
     * The footer credit as HTML (every renderer shows footer slots the same way).
     */
    public function renderCredit(): string
    {
        return Credit::html($this->getCreditUrl());
    }

    public function emailVerification(bool $condition = true): static
    {
        $this->hasEmailVerification = $condition;

        return $this;
    }

    public function hasEmailVerification(): bool
    {
        return $this->hasEmailVerification;
    }

    /**
     * @param  class-string<Page>|bool|null  $page
     */
    public function profile(string|bool|null $page = EditProfile::class, bool $isSimple = true): static
    {
        $this->hasProfile = $page !== false && $page !== null;
        $this->profilePage = is_string($page) ? $page : EditProfile::class;
        $this->isProfilePageSimple = $isSimple;

        return $this;
    }

    public function hasProfile(): bool
    {
        return $this->hasProfile;
    }

    public function isProfilePageSimple(): bool
    {
        return $this->isProfilePageSimple;
    }

    /**
     * @param  array<string|Closure>  $middleware
     */
    public function middleware(array $middleware, bool $isPersistent = false): static
    {
        $this->middleware = [...$this->middleware, ...$middleware];

        return $this;
    }

    /**
     * @param  array<string|Closure>  $middleware
     */
    public function authMiddleware(array $middleware): static
    {
        $this->authMiddleware = [...$this->authMiddleware, ...$middleware];

        return $this;
    }

    /**
     * @return array<string|Closure>
     */
    public function getMiddleware(): array
    {
        return [SetUpPanel::class.':'.$this->id, ...$this->middleware, SetPanelLocale::class, PreserveFlashData::class, HandleInertiaRequests::class];
    }

    /**
     * @return array<string|Closure>
     */
    public function getAuthMiddleware(): array
    {
        $middleware = [Authenticate::class.':'.$this->id, ...$this->authMiddleware];

        if ($this->hasEmailVerification) {
            $middleware[] = Http\Middleware\EnsureEmailIsVerified::class.':'.$this->id;
        }

        return [...$middleware, ...$this->getMultiFactorAuthenticationMiddleware()];
    }

    public function userName(?Closure $callback): static
    {
        $this->userNameUsing = $callback;

        return $this;
    }

    public function getUserName(Authenticatable $user): string
    {
        if ($this->userNameUsing !== null) {
            return (string) $this->evaluate($this->userNameUsing, ['user' => $user]);
        }

        if ($user instanceof HasName) {
            return Support\Html::toText($user->getAlfakitName());
        }

        return (string) ($user->getAttribute('name') ?? $user->getAttribute('email') ?? $user->getAuthIdentifier());
    }

    /**
     * `HasAvatar`, else an `avatar_url` / `avatar` attribute, else the panel's
     * `defaultAvatarProvider()` (null without one: the renderer shows initials).
     */
    public function getUserAvatarUrl(Authenticatable $user): ?string
    {
        $url = $user instanceof HasAvatar
            ? $user->getAlfakitAvatarUrl()
            : ($user->getAttribute('avatar_url') ?? $user->getAttribute('avatar') ?? null);

        return is_string($url) && $url !== '' ? $url : $this->getDefaultAvatarUrl($user);
    }

    // ---------------------------------------------------------------- Branding

    public function brandName(string|Closure|null $name): static
    {
        $this->brandName = $name;

        return $this;
    }

    public function getBrandName(): string
    {
        return $this->evaluate($this->brandName) ?? config('app.name', 'AlfaKit');
    }

    public function brandLogo(string|Closure|null $logo): static
    {
        $this->brandLogo = $logo;

        return $this;
    }

    public function darkModeBrandLogo(string|Closure|null $logo): static
    {
        $this->darkModeBrandLogo = $logo;

        return $this;
    }

    public function brandLogoHeight(string|Closure|null $height): static
    {
        $this->brandLogoHeight = $height;

        return $this;
    }

    public function getBrandLogo(): ?string
    {
        $logo = $this->evaluate($this->brandLogo);

        return is_string($logo) && $logo !== '' ? $logo : null;
    }

    public function getDarkModeBrandLogo(): ?string
    {
        $logo = $this->evaluate($this->darkModeBrandLogo);

        return is_string($logo) && $logo !== '' ? $logo : null;
    }

    public function getBrandLogoHeight(): ?string
    {
        $height = $this->evaluate($this->brandLogoHeight);

        return is_string($height) && $height !== '' ? $height : null;
    }

    public function favicon(string|Closure|null $url): static
    {
        $this->favicon = $url;

        return $this;
    }

    public function getFavicon(): ?string
    {
        return $this->evaluate($this->favicon);
    }

    /**
     * Both arguments may be closures, resolved per request: `darkMode(fn (): bool =>
     * auth()->user()?->prefersDarkMode() ?? true)`.
     */
    public function darkMode(bool|Closure $condition = true, bool|Closure $isForced = false): static
    {
        $this->hasDarkMode = $condition;
        $this->hasDarkModeForced = $isForced;

        return $this;
    }

    public function hasDarkMode(): bool
    {
        return (bool) $this->evaluate($this->hasDarkMode);
    }

    public function hasDarkModeForced(): bool
    {
        return (bool) $this->evaluate($this->hasDarkModeForced);
    }

    public function sidebarCollapsibleOnDesktop(bool $condition = true): static
    {
        $this->isSidebarCollapsibleOnDesktop = $condition;

        return $this;
    }

    public function sidebarFullyCollapsibleOnDesktop(bool $condition = true): static
    {
        $this->isSidebarFullyCollapsibleOnDesktop = $condition;

        return $this;
    }

    public function topNavigation(bool $condition = true): static
    {
        $this->hasTopNavigation = $condition;

        return $this->flushThemeCache();
    }

    public function maxContentWidth(Width|string|Closure|null $width): static
    {
        $this->maxContentWidth = $width;

        return $this;
    }

    public function getMaxContentWidth(): ?string
    {
        $width = $this->evaluate($this->maxContentWidth);

        return $width instanceof Width ? $width->value : $width;
    }

    /**
     * `hasNavigationHierarchy: true` starts the breadcrumbs with the page's navigation
     * group and parent item.
     */
    public function breadcrumbs(bool $condition = true, bool|Closure|null $hasNavigationHierarchy = null): static
    {
        $this->hasBreadcrumbs = $condition;

        if ($hasNavigationHierarchy !== null) {
            $this->navigationHierarchyBreadcrumbs($hasNavigationHierarchy);
        }

        return $this;
    }

    public function hasBreadcrumbs(): bool
    {
        return $this->hasBreadcrumbs;
    }

    public function unsavedChangesAlerts(bool $condition = true): static
    {
        $this->hasUnsavedChangesAlerts = $condition;

        return $this;
    }

    public function hasUnsavedChangesAlerts(): bool
    {
        return $this->hasUnsavedChangesAlerts;
    }

    public function spa(bool $condition = true): static
    {
        $this->hasSpaMode = $condition;

        return $this;
    }

    public function homeUrl(string|Closure|null $url): static
    {
        $this->homeUrl = $url;

        return $this;
    }

    // ---------------------------------------------------------------- Components

    /**
     * Resource classes, and configured registrations (`OrderResource::make('archived')`).
     *
     * @param  array<class-string<resource>|ResourceConfiguration>  $resources
     */
    public function resources(array $resources): static
    {
        $classes = [];

        foreach ($resources as $resource) {
            if ($resource instanceof ResourceConfiguration) {
                $this->registerResourceConfiguration($resource);
            } else {
                $classes[] = $resource;
            }
        }

        $this->resources = array_values(array_unique([...$this->resources, ...$classes]));

        return $this->flushComponentCache();
    }

    public function discoverResources(string $in, string $for): static
    {
        $this->resourceDiscovery[] = ['directory' => $in, 'namespace' => $for];

        return $this->flushComponentCache();
    }

    /**
     * Where `discoverResources()` looks: the generators write a panel's classes there.
     *
     * @return list<array{directory: string, namespace: string}>
     */
    public function getResourceDiscovery(): array
    {
        return array_values($this->resourceDiscovery);
    }

    /**
     * Page classes, and configured registrations (`SettingsPage::make('general')`).
     *
     * @param  array<class-string<Page>|PageConfiguration>  $pages
     */
    public function pages(array $pages): static
    {
        $classes = [];

        foreach ($pages as $page) {
            if ($page instanceof PageConfiguration) {
                $this->registerPageConfiguration($page);
            } else {
                $classes[] = $page;
            }
        }

        $this->pages = array_values(array_unique([...$this->pages, ...$classes]));

        return $this->flushComponentCache();
    }

    public function discoverPages(string $in, string $for): static
    {
        $this->pageDiscovery[] = ['directory' => $in, 'namespace' => $for];

        return $this->flushComponentCache();
    }

    /**
     * @param  array<class-string<Widget>>  $widgets
     */
    public function widgets(array $widgets): static
    {
        $this->widgets = array_values(array_unique([...$this->widgets, ...$widgets]));

        return $this->flushComponentCache();
    }

    public function discoverWidgets(string $in, string $for): static
    {
        $this->widgetDiscovery[] = ['directory' => $in, 'namespace' => $for];

        return $this->flushComponentCache();
    }

    /**
     * Offer saved views ("My views", team-shared views) on every table of the panel. A
     * table's own `savedViews(false)` wins.
     */
    public function tableSavedViews(bool $condition = true): static
    {
        $this->hasTableSavedViews = $condition;

        return $this;
    }

    public function hasTableSavedViews(): bool
    {
        return $this->hasTableSavedViews;
    }

    /**
     * Let users personalise every dashboard of the panel (reorder, resize, hide and add
     * widgets). A dashboard's own `Dashboard::customizable()` wins.
     */
    public function customizableDashboards(bool $condition = true): static
    {
        $this->hasCustomizableDashboards = $condition;

        return $this;
    }

    public function hasCustomizableDashboards(): bool
    {
        return $this->hasCustomizableDashboards;
    }

    /**
     * Widgets users may add to a customizable dashboard from its library, besides the
     * dashboard's default widgets. Each is still filtered by its `canView()`.
     *
     * @param  array<class-string<Widget>|Widget>  $widgets
     */
    public function availableDashboardWidgets(array $widgets): static
    {
        $this->availableDashboardWidgets = [...$this->availableDashboardWidgets, ...$widgets];

        return $this;
    }

    /**
     * @return array<class-string<Widget>|Widget>
     */
    public function getAvailableDashboardWidgets(): array
    {
        return $this->availableDashboardWidgets;
    }

    /**
     * @return array<class-string<resource>>
     */
    public function getResources(): array
    {
        return $this->resolveComponents($this->resources, $this->resourceDiscovery, Resource::class, 'resources');
    }

    /**
     * @return array<class-string<Page>>
     */
    public function getPages(): array
    {
        $pages = $this->resolveComponents($this->pages, $this->pageDiscovery, Page::class, 'pages');

        // An app's own `Dashboard extends Dashboard` (discovered) replaces the registered
        // parent on the same route instead of adding a second one.
        return $this->resolvedComponents['pages:overridden'] ??= array_values(array_filter($pages, static function (string $page) use ($pages): bool {
            foreach ($pages as $other) {
                if ($other !== $page && is_subclass_of($other, $page) && $other::getRoutePath() === $page::getRoutePath()) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return $this->resolveComponents($this->widgets, $this->widgetDiscovery, Widget::class, 'widgets');
    }

    /**
     * @param  array<class-string>  $registered
     * @param  array<array{directory: string, namespace: string}>  $discovery
     * @param  class-string  $baseClass
     * @return array<class-string>
     */
    protected function resolveComponents(array $registered, array $discovery, string $baseClass, string $cacheKey): array
    {
        if (isset($this->resolvedComponents[$cacheKey])) {
            return $this->resolvedComponents[$cacheKey];
        }

        $classes = $registered;
        $cachedDiscovery = $this->readDiscoveryCache($cacheKey);

        if ($cachedDiscovery !== null) {
            $classes = [...$classes, ...$cachedDiscovery];
        } else {
            foreach ($discovery as ['directory' => $directory, 'namespace' => $namespace]) {
                array_push($classes, ...$this->discoverClasses($directory, $namespace, $baseClass));
            }
        }

        return $this->resolvedComponents[$cacheKey] = array_values(array_unique($classes));
    }

    /**
     * Forget the resolved resources, pages and widgets so they are discovered again (or read
     * from a fresh `alfakit:optimize` cache) on next access.
     */
    public function flushComponentCache(): static
    {
        $this->resolvedComponents = [];

        return $this;
    }

    /**
     * @param  class-string  $baseClass
     * @return array<class-string>
     */
    protected function discoverClasses(string $directory, string $namespace, string $baseClass): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $classes = [];
        $namespace = rtrim($namespace, '\\');

        foreach ((new Finder)->files()->in($directory)->name('*.php') as $file) {
            $relative = Str::of($file->getRelativePathname())->beforeLast('.php')->replace(['/', '\\'], '\\')->toString();
            $class = "{$namespace}\\{$relative}";

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf($baseClass)) {
                continue;
            }

            // `protected static bool $isDiscovered = false` keeps a class out of discovery
            // (it can still be registered by hand).
            if (method_exists($class, 'isDiscovered') && ! $class::isDiscovered()) {
                continue;
            }

            $classes[] = $class;
        }

        sort($classes);

        return $classes;
    }

    /**
     * @return array<class-string>|null
     */
    protected function readDiscoveryCache(string $key): ?array
    {
        $file = rtrim((string) config('alfakit.cache_path'), '/')."/{$this->id}.php";

        if (! is_file($file)) {
            return null;
        }

        $cached = require $file;

        return $cached[$key] ?? null;
    }

    /**
     * Everything that would be discovered right now, for `alfakit:optimize`.
     *
     * @return array<string, array<class-string>>
     */
    public function getDiscoverableComponents(): array
    {
        $discover = fn (array $discovery, string $baseClass): array => array_merge(...array_map(
            fn (array $d): array => $this->discoverClasses($d['directory'], $d['namespace'], $baseClass),
            $discovery,
        ) ?: [[]]);

        return [
            'resources' => $discover($this->resourceDiscovery, Resource::class),
            'pages' => $discover($this->pageDiscovery, Page::class),
            'widgets' => $discover($this->widgetDiscovery, Widget::class),
        ];
    }

    /**
     * @return class-string<resource>|null
     */
    public function getModelResource(string|Model $model): ?string
    {
        $model = $model instanceof Model ? $model::class : $model;

        foreach ($this->getResources() as $resource) {
            if ($resource::getModel() === $model) {
                return $resource;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Navigation

    /**
     * @param  array<NavigationItem>|Closure  $items
     */
    public function navigationItems(array|Closure $items): static
    {
        $this->navigationItems = $items;

        return $this;
    }

    /**
     * @param  array<NavigationGroup|string|UnitEnum>|Closure  $groups
     */
    public function navigationGroups(array|Closure $groups): static
    {
        $this->navigationGroups = $groups;

        return $this;
    }

    /**
     * `false` hides the navigation. A closure either takes full control,
     * `fn (NavigationBuilder $builder): NavigationBuilder`, or decides per request:
     * `fn (): bool => auth()->user()->hasCompletedOnboarding()` (`true` keeps the
     * auto-discovered items, `false` hides the navigation).
     */
    public function navigation(Closure|bool $builder = true): static
    {
        if (is_bool($builder)) {
            $this->hasNavigation = $builder;
        } else {
            $this->navigationBuilder = $builder;
        }

        return $this;
    }

    /**
     * `Action`s (or `MenuItem`s) for the user menu. Nested arrays are groups; the keys
     * `profile` and `logout` customise the built-in entries (an `Action`, or a closure
     * `fn (Action $action) => $action->label('Edit profile')`; hide them with `->hidden()`).
     *
     * @param  array<array-key, mixed>|Closure  $items
     */
    public function userMenuItems(array|Closure $items): static
    {
        $this->userMenuItems = $items;

        return $this;
    }

    public function userMenu(bool $condition = true, Support\Enums\UserMenuPosition|string|Closure|null $position = null): static
    {
        $this->hasUserMenu = $condition;

        if ($position !== null) {
            $this->userMenuPosition($position);
        }

        return $this;
    }

    /**
     * Whether the panel shows a navigation (resolves a `navigation()` closure).
     */
    public function hasNavigation(): bool
    {
        return $this->resolveNavigationBuilder() !== false;
    }

    /**
     * Whether a `navigation()` closure builds the navigation itself.
     */
    public function hasCustomNavigation(): bool
    {
        return $this->resolveNavigationBuilder() instanceof Navigation\NavigationBuilder;
    }

    /**
     * `false` (no navigation), `true` (auto-discovered items) or the builder a
     * `navigation()` closure returned.
     */
    protected function resolveNavigationBuilder(): Navigation\NavigationBuilder|bool
    {
        if (! $this->hasNavigation) {
            return false;
        }

        if ($this->navigationBuilder === null) {
            return true;
        }

        $builder = new Navigation\NavigationBuilder;
        $result = $this->evaluate($this->navigationBuilder, ['builder' => $builder], [Navigation\NavigationBuilder::class => $builder]);

        return $result instanceof Navigation\NavigationBuilder ? $result : (bool) $result;
    }

    /**
     * @return array<NavigationGroup>
     */
    public function getNavigation(): array
    {
        $resolved = $this->resolveNavigationBuilder();

        if ($resolved === false) {
            return [];
        }

        if ($resolved instanceof Navigation\NavigationBuilder) {
            return $resolved->getNavigation();
        }

        /** @var array<NavigationItem> $items */
        $items = [...($this->evaluate($this->navigationItems) ?? [])];

        // Each registration (configured ones included) builds its items as itself.
        foreach ($this->mapPageRegistrations(static fn (string $page): array => $page::getNavigationItems()) as $pageItems) {
            array_push($items, ...$pageItems);
        }

        // Guests only see the resources they may open (pages check this themselves).
        $isGuest = $this->auth()->guest();

        // Each registration (configured ones included) builds its items as itself.
        foreach ($this->mapResourceRegistrations(fn (string $resource): array => $isGuest && ! $this->isGuestAccessible($resource, $this->getTenant()) ? [] : $resource::getNavigationItems()) as $resourceItems) {
            array_push($items, ...$resourceItems);
        }

        $items = array_values(array_filter($items, static fn (NavigationItem $item): bool => $item->isVisible()));

        // Nest items that declare a parent (by its key, a page or resource class, or its label).
        $topLevel = NavigationItem::nest($items);

        $groupDefinitions = collect($this->evaluate($this->navigationGroups) ?? [])
            ->map(static fn (NavigationGroup|string|UnitEnum $group): NavigationGroup => $group instanceof NavigationGroup ? $group : NavigationGroup::make($group))
            ->keyBy(static fn (NavigationGroup $group): string => (string) $group->getLabel());

        $grouped = [];
        $sources = [];
        foreach ($topLevel as $item) {
            $label = $item->getGroup() ?? '';
            $grouped[$label][] = $item;
            // An enum group (`HasLabel`, `HasIcon`) builds an undefined group with its icon.
            $sources[$label] ??= $item->getGroupSource();
        }

        $order = ['', ...$groupDefinitions->keys()->all()];
        $groups = [];

        foreach (array_unique([...$order, ...array_keys($grouped)]) as $label) {
            if (! isset($grouped[$label])) {
                continue;
            }

            $source = $sources[$label] ?? null;
            $group = $groupDefinitions->get($label) ?? NavigationGroup::make($label === '' ? null : ($source instanceof UnitEnum ? $source : $label));
            $groups[] = $group->items($grouped[$label]);
        }

        return $groups;
    }

    /**
     * The visible custom user menu entries (without the keyed built-ins), in order.
     *
     * @return array<Action|MenuItem>
     */
    public function getUserMenuItems(): array
    {
        return array_map(static fn (array $entry): Action|MenuItem => $entry[0], $this->visibleMenuEntries($this->getUserMenuConfiguration()['entries']));
    }

    // ---------------------------------------------------------------- Features

    public function databaseNotifications(bool $condition = true, string|Closure|null $pollingInterval = '30s', Support\Enums\DatabaseNotificationsPosition|string|Closure|null $position = null): static
    {
        $this->hasDatabaseNotifications = $condition;
        $this->databaseNotificationsPolling = $pollingInterval;

        if ($position !== null) {
            $this->databaseNotificationsPosition($position);
        }

        return $this;
    }

    public function hasDatabaseNotifications(): bool
    {
        return $this->hasDatabaseNotifications;
    }

    /**
     * @param  array<string>  $keyBindings
     */
    public function globalSearch(bool $condition = true, array $keyBindings = ['mod+k'], Support\Enums\GlobalSearchPosition|string|Closure|null $position = null): static
    {
        $this->hasGlobalSearch = $condition;
        $this->globalSearchKeyBindings = $keyBindings;

        if ($position !== null) {
            $this->globalSearchPosition($position);
        }

        return $this;
    }

    public function hasGlobalSearch(): bool
    {
        if (! $this->hasGlobalSearch) {
            return false;
        }

        foreach ($this->getResources() as $resource) {
            if ($resource::canGloballySearch()) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- Plugins

    public function plugin(Plugin $plugin): static
    {
        $this->plugins[$plugin->getId()] = $plugin;
        $plugin->register($this);

        return $this;
    }

    /**
     * @param  array<Plugin>  $plugins
     */
    public function plugins(array $plugins): static
    {
        foreach ($plugins as $plugin) {
            $this->plugin($plugin);
        }

        return $this;
    }

    public function getPlugin(string $id): Plugin
    {
        return $this->plugins[$id] ?? throw new InvalidArgumentException("Plugin [{$id}] is not registered on panel [{$this->id}].");
    }

    public function hasPlugin(string $id): bool
    {
        return isset($this->plugins[$id]);
    }

    public function bootUsing(Closure $callback): static
    {
        $this->bootCallbacks[] = $callback;

        return $this;
    }

    /**
     * Boot the panel for the current request (called by SetUpPanel middleware).
     */
    public function boot(): void
    {
        if ($this->isBooted) {
            return;
        }

        $this->isBooted = true;
        $this->registerPanelAssets();

        foreach ($this->plugins as $plugin) {
            $plugin->boot($this);
        }

        foreach ($this->bootCallbacks as $callback) {
            $this->evaluate($callback);
        }
    }

    // ---------------------------------------------------------------- Tenancy

    /**
     * @param  class-string<Model>  $model
     */
    public function tenant(string $model, ?string $slugAttribute = null, ?string $ownershipRelationship = null): static
    {
        $this->tenantModel = $model;
        $this->tenantSlugAttribute = $slugAttribute;
        $this->tenantOwnershipRelationshipName = $ownershipRelationship;

        return $this;
    }

    public function hasTenancy(): bool
    {
        return $this->tenantModel !== null;
    }

    /**
     * @return class-string<Model>|null
     */
    public function getTenantModel(): ?string
    {
        return $this->tenantModel;
    }

    public function getTenantSlugAttribute(): ?string
    {
        return $this->tenantSlugAttribute;
    }

    public function getTenantOwnershipRelationshipName(): string
    {
        return $this->tenantOwnershipRelationshipName ?? Str::camel(class_basename((string) $this->tenantModel));
    }

    public function tenantRoutePrefix(string|Closure|null $prefix): static
    {
        $this->tenantRoutePrefix = $prefix;

        return $this;
    }

    public function getTenantRoutePrefix(): ?string
    {
        return $this->evaluate($this->tenantRoutePrefix);
    }

    /**
     * Let users create tenants on a page that extends `RegisterTenant` (`null` turns the
     * page off). The base page is abstract, so a concrete class is required.
     *
     * @param  class-string<RegisterTenant>|null  $page
     */
    public function tenantRegistration(?string $page): static
    {
        $this->tenantRegistrationPage = $page === null ? null : $this->ensureTenantPage($page, RegisterTenant::class, 'tenantRegistration');

        return $this;
    }

    public function hasTenantRegistration(): bool
    {
        return $this->tenantRegistrationPage !== null;
    }

    /**
     * Let users edit the current tenant on a page that extends `EditTenantProfile` (`null`
     * turns the page off). The base page is abstract, so a concrete class is required.
     *
     * @param  class-string<EditTenantProfile>|null  $page
     */
    public function tenantProfile(?string $page): static
    {
        $this->tenantProfilePage = $page === null ? null : $this->ensureTenantPage($page, EditTenantProfile::class, 'tenantProfile');

        return $this;
    }

    /**
     * @template T of Page
     *
     * @param  class-string<T>  $base
     * @return class-string<T>
     */
    protected function ensureTenantPage(string $page, string $base, string $method): string
    {
        if (! is_subclass_of($page, $base) || (new ReflectionClass($page))->isAbstract()) {
            throw new InvalidArgumentException(sprintf(
                'Panel::%s() needs a concrete page class that extends %s, [%s] given. Create one that implements getLabel()%s and form().',
                $method,
                $base,
                $page,
                $base === RegisterTenant::class ? ', handleRegistration()' : '',
            ));
        }

        return $page;
    }

    public function hasTenantProfile(): bool
    {
        return $this->tenantProfilePage !== null;
    }

    /**
     * `Action`s (or `MenuItem`s) for the tenant menu. Nested arrays are groups; the keys
     * `profile`, `register` and `billing` customise the built-in entries.
     *
     * @param  array<array-key, mixed>|Closure  $items
     */
    public function tenantMenuItems(array|Closure $items): static
    {
        $this->tenantMenuItems = $items;

        return $this;
    }

    public function tenantMenu(bool $condition = true): static
    {
        $this->hasTenantMenu = $condition;

        return $this;
    }

    /**
     * @param  array<string|Closure>  $middleware
     */
    public function tenantMiddleware(array $middleware): static
    {
        $this->tenantMiddleware = [...$this->tenantMiddleware, ...$middleware];

        return $this;
    }

    /**
     * @return array<string|Closure>
     */
    public function getTenantMiddleware(): array
    {
        return [IdentifyTenant::class.':'.$this->id, ...$this->tenantMiddleware, ...$this->getTenantBillingMiddleware()];
    }

    public function getTenant(): ?Model
    {
        return alfakit()->getTenant();
    }

    /**
     * Find a tenant by its route key and check the user may use it. Fails closed: users
     * that don't implement `HasTenants` can't access any tenant.
     */
    public function findTenantFor(mixed $key, ?Authenticatable $user): ?Model
    {
        $model = $this->getTenantModel();
        $slugAttribute = $this->getTenantSlugAttribute();

        // Route parameters may already hold the resolved tenant; look it up again so
        // membership is always re-checked against fresh data.
        if ($key instanceof Model && $model !== null && $key instanceof $model) {
            $key = $this->getTenantRouteKey($key);
        }

        if ($model === null || ! is_scalar($key) || $key === '') {
            return null;
        }

        $tenant = $slugAttribute !== null
            ? $model::query()->where($slugAttribute, $key)->first()
            : $model::query()->find($key);

        if ($tenant === null || ! $user instanceof HasTenants || ! $user->canAccessTenant($tenant)) {
            return null;
        }

        return $tenant;
    }

    /**
     * The value that identifies a tenant in URLs: its slug attribute, or its key.
     */
    public function getTenantRouteKey(Model $tenant): mixed
    {
        $slugAttribute = $this->getTenantSlugAttribute();

        return $slugAttribute !== null ? $tenant->getAttribute($slugAttribute) : $tenant->getKey();
    }

    /**
     * The tenant's name as text (an `Htmlable` name without its markup).
     */
    public function getTenantLabel(Model $tenant): string
    {
        if ($tenant instanceof HasName) {
            return Support\Html::toText($tenant->getAlfakitName());
        }

        return (string) ($tenant->getAttribute('name') ?? $tenant->getKey());
    }

    /**
     * The tenant's name: a string, or `Htmlable` markup from `HasName::getAlfakitName()`.
     */
    public function getTenantName(Model $tenant): string|\Illuminate\Contracts\Support\Htmlable
    {
        return $tenant instanceof HasName ? $tenant->getAlfakitName() : $this->getTenantLabel($tenant);
    }

    /**
     * The tenant's name for the tenant menu: `name` is sanitised HTML with `nameHtml`
     * when `HasName` returns `Htmlable`.
     *
     * @return array{name: string, nameHtml?: true}
     */
    protected function getTenantNameSchema(Model $tenant): array
    {
        $name = $this->getTenantName($tenant);

        return $name instanceof \Illuminate\Contracts\Support\Htmlable
            ? ['name' => Support\Html::sanitize($name), 'nameHtml' => true]
            : ['name' => $name];
    }

    /**
     * `HasCurrentTenantLabel`: a caption shown with the current tenant's name in the
     * tenant menu ("Active team"); null without the contract.
     */
    public function getCurrentTenantLabel(Model $tenant): ?string
    {
        if (! $tenant instanceof HasCurrentTenantLabel) {
            return null;
        }

        $label = $tenant->getCurrentTenantLabel();

        return $label === '' ? null : $label;
    }

    public function getTenantAvatarUrl(Model $tenant): ?string
    {
        $url = $tenant instanceof HasAvatar ? $tenant->getAlfakitAvatarUrl() : ($tenant->getAttribute('avatar_url') ?? null);

        return is_string($url) && $url !== '' ? $url : $this->getDefaultAvatarUrl($tenant);
    }

    /**
     * @return array<Model>
     */
    public function getUserTenants(Authenticatable $user): array
    {
        if (! $user instanceof HasTenants) {
            return [];
        }

        $tenants = $user->getTenants($this);

        return $tenants instanceof \Illuminate\Support\Collection ? $tenants->all() : $tenants;
    }

    /**
     * The tenant the panel root redirects to: the user's `HasDefaultTenant` choice when it is
     * one of their tenants, otherwise their first tenant.
     */
    public function getDefaultTenant(Authenticatable $user): ?Model
    {
        $tenants = $this->getUserTenants($user);

        if ($user instanceof HasDefaultTenant) {
            $default = $user->getDefaultTenant($this);

            if ($default !== null && $user instanceof HasTenants && $user->canAccessTenant($default)) {
                return $default;
            }
        }

        return $tenants[0] ?? null;
    }

    // ---------------------------------------------------------------- Routing & URLs

    public function getRouteNamePrefix(): string
    {
        return "alfakit.{$this->id}.";
    }

    public function generateRouteName(string $name): string
    {
        return $this->getRouteNamePrefix().$name;
    }

    /**
     * Generate a URL for a panel route, injecting the current tenant when needed.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function route(string $name, array $parameters = [], bool $isAbsolute = true): string
    {
        $routeName = $this->generateRouteName($name);

        if ($this->hasTenancy() && ! array_key_exists('tenant', $parameters) && Route::has($routeName)) {
            $route = Route::getRoutes()->getByName($routeName);

            if ($route !== null && in_array('tenant', $route->parameterNames(), true)) {
                $parameters['tenant'] = $this->getTenant();
            }
        }

        if (isset($parameters['tenant']) && $parameters['tenant'] instanceof Model) {
            $parameters['tenant'] = $this->tenantSlugAttribute !== null
                ? $parameters['tenant']->getAttribute($this->tenantSlugAttribute)
                : $parameters['tenant']->getKey();
        }

        return $this->onCurrentDomain(route($routeName, $parameters, $isAbsolute));
    }

    /**
     * With `domains()`, links point to the domain the current request came in on (the
     * first domain outside a request to one of them), whichever domain's route the name
     * resolved to.
     */
    protected function onCurrentDomain(string $url): string
    {
        if (count($this->domains) < 2) {
            return $url;
        }

        $urlHost = parse_url($url, PHP_URL_HOST);

        if (! is_string($urlHost) || ! in_array($urlHost, $this->domains, true)) {
            return $url;
        }

        $host = app()->bound('request') ? request()->getHost() : null;
        $target = in_array($host, $this->domains, true) ? $host : $this->domains[0];

        return $target === $urlHost ? $url : (string) preg_replace('#^(https?://)'.preg_quote($urlHost, '#').'#', '$1'.$target, $url, 1);
    }

    /**
     * The panel's home URL (dashboard or first navigation item).
     */
    public function getUrl(?Model $tenant = null): string
    {
        $tenant ??= $this->getTenant();

        if ($this->hasTenancy() && $tenant === null) {
            $user = $this->auth()->user();
            $tenant = $user !== null ? $this->getDefaultTenant($user) : null;

            if ($tenant === null) {
                return $this->hasTenantRegistration() ? $this->route('tenant.registration') : url($this->path);
            }
        }

        $home = $this->evaluate($this->homeUrl);

        if ($home !== null) {
            return $home;
        }

        $parameters = $tenant !== null ? ['tenant' => $tenant] : [];

        // With several dashboards, the one at the panel root is home.
        foreach ($this->getPages() as $page) {
            if (is_a($page, Dashboard::class, true) && trim($page::getRoutePath(), '/') === '') {
                return $this->route($page::getRelativeRouteName(), $parameters);
            }
        }

        foreach ($this->getPages() as $page) {
            if (is_a($page, Dashboard::class, true)) {
                return $this->route($page::getRelativeRouteName(), $parameters);
            }
        }

        $pages = $this->getPages();
        $resources = $this->getResources();

        // The first item of the navigation, never a hidden page such as the users
        // resource's forced `ChangePassword` page (starter-kit panels have no dashboard).
        foreach ($pages as $page) {
            if ($page::shouldRegisterNavigation()) {
                return $this->route($page::getRelativeRouteName(), $parameters);
            }
        }

        foreach ($resources as $resource) {
            if ($resource::shouldRegisterNavigation() && $resource::hasPage('index')) {
                return $resource::getUrl('index', $parameters);
            }
        }

        if ($pages !== []) {
            return $this->route($pages[0]::getRelativeRouteName(), $parameters);
        }

        if ($resources !== []) {
            return $resources[0]::getUrl('index', $parameters);
        }

        return url($this->path);
    }

    public function getLoginUrl(): ?string
    {
        // `starterKit()`: guests sign in on the host application's login page.
        if ($this->isStarterKit && ! $this->hasLogin) {
            return $this->getStarterKitLoginUrl();
        }

        return $this->hasLogin ? $this->route('auth.login') : null;
    }

    public function getRegistrationUrl(): ?string
    {
        return $this->hasRegistration ? $this->route('auth.register') : null;
    }

    public function getPasswordResetUrl(): ?string
    {
        return $this->hasPasswordReset ? $this->route('auth.password-reset.request') : null;
    }

    public function getLogoutUrl(): string
    {
        return $this->route('auth.logout');
    }

    public function getProfileUrl(): ?string
    {
        return $this->hasProfile ? $this->route('auth.profile') : null;
    }

    public function getEmailVerificationPromptUrl(): ?string
    {
        return $this->hasEmailVerification ? $this->route('auth.email-verification.prompt') : null;
    }

    /**
     * The tenant registration page's own label ("Register team"), for the tenant menu.
     */
    public function getTenantRegistrationLabel(): ?string
    {
        $page = $this->tenantRegistrationPage;

        return $page === null ? null : $page::getLabel();
    }

    /**
     * The tenant profile page's own label ("Team settings"), for the tenant menu.
     */
    public function getTenantProfileLabel(): ?string
    {
        $page = $this->tenantProfilePage;

        return $page === null ? null : $page::getLabel();
    }

    public function getTenantRegistrationUrl(): ?string
    {
        return $this->hasTenantRegistration() ? $this->route('tenant.registration') : null;
    }

    public function getTenantProfileUrl(): ?string
    {
        return $this->hasTenantProfile() && $this->getTenant() !== null ? $this->route('tenant.profile') : null;
    }

    /**
     * Register every route for this panel. Called once the application has booted.
     */
    public function registerRoutes(Router $router): void
    {
        $this->registerPanelAssets();

        foreach ($this->getDomains() as $domain) {
            $this->registerRoutesForDomain($router, $domain);
        }
    }

    protected function registerRoutesForDomain(Router $router, ?string $domain): void
    {
        $router->domain($domain)
            ->prefix($this->path)
            ->name($this->getRouteNamePrefix())
            ->middleware($this->getMiddleware())
            ->group(function (Router $router): void {
                // `pwa()`: manifest, service worker, offline page and icons, before any page
                // route so `/{tenant}` or a page slug never shadows them.
                $this->registerPwaRoutes($router);

                // Tenant-less routes: auth and tenant registration.
                if ($this->hasLogin) {
                    $router->get($this->getLoginRoutePath(), $this->loginPage)->name('auth.login')->middleware(Http\Middleware\RedirectIfAuthenticated::class.':'.$this->id);
                }

                if ($this->hasRegistration) {
                    $router->get($this->getRegistrationRoutePath(), $this->registrationPage)->name('auth.register')->middleware(Http\Middleware\RedirectIfAuthenticated::class.':'.$this->id);
                }

                if ($this->hasPasswordReset) {
                    $router->get($this->getPasswordResetRequestRoutePath(), RequestPasswordReset::class)->name('auth.password-reset.request')->middleware(Http\Middleware\RedirectIfAuthenticated::class.':'.$this->id);
                    $router->get($this->getPasswordResetRoutePath(), ResetPassword::class)->name('auth.password-reset.reset')->middleware(Http\Middleware\RedirectIfAuthenticated::class.':'.$this->id);
                }

                $router->post('/logout', LogoutController::class)->name('auth.logout');

                $this->registerImpersonationRoutes($router);
                $this->registerCustomRoutes($router, authenticated: false);
                $this->registerMultiFactorAuthenticationRoutes($router);
                $this->registerEmailChangeVerificationRoutes($router);

                if ($this->hasEmailVerification) {
                    $router->get($this->getEmailVerificationPromptRoutePath(), EmailVerificationPrompt::class)->name('auth.email-verification.prompt')->middleware(EmailVerificationPrompt::getPanelAuthMiddleware($this));
                    $router->get($this->getEmailVerificationRoutePath().'/{id}/{hash}', VerifyEmailController::class)->name('auth.email-verification.verify')->middleware([Authenticate::class.':'.$this->id, 'signed', 'throttle:6,1']);
                }

                if ($this->hasProfile) {
                    $router->get('/profile', $this->profilePage)->name('auth.profile')->middleware($this->getAuthMiddleware());
                }

                if ($this->hasTenancy() && $this->hasTenantRegistration()) {
                    $router->get('/new', $this->tenantRegistrationPage)->name('tenant.registration')->middleware($this->getAuthMiddleware());
                }

                // `tenantRoutePrefix('team')`: `/admin/team/{tenant}/…`.
                $tenantPrefix = trim((string) $this->getTenantRoutePrefix(), '/');
                $prefix = $this->hasTenancy() && ! $this->hasTenantDomain() ? ($tenantPrefix === '' ? '' : '/'.$tenantPrefix).'/{tenant}' : '';
                $middleware = $this->hasTenancy() ? [...$this->getAuthMiddleware(), ...$this->getTenantMiddleware()] : $this->getAuthMiddleware();

                $registerTenantRoutes = function () use ($router, $prefix, $middleware): void {
                    $group = $router->prefix($prefix)->middleware($middleware);

                    // `tenantDomain()`: the tenant comes from the host instead of the path.
                    if ($this->hasTenantDomain()) {
                        $group->domain((string) $this->getTenantDomain())->where(['tenant' => $this->getTenantDomainParameterPattern()]);
                    }

                    $group->group(function (Router $router): void {
                        if ($this->hasTenancy()) {
                            $this->registerInternalRoutes($router);

                            if ($this->hasTenantProfile()) {
                                // On tenant domains `/profile` is the user's profile.
                                $router->get($this->hasTenantDomain() ? '/tenant-profile' : '/profile', $this->tenantProfilePage)->name('tenant.profile');
                            }

                            $this->registerTenantBillingRoutes($router);
                        }

                        $this->mapPageRegistrations(fn (string $page) => $page::registerRoutes($this));

                        $this->mapResourceRegistrations(fn (string $resource) => $resource::registerRoutes($this));

                        $this->registerCustomRoutes($router, authenticated: true);
                    });
                };

                // On tenant domains the tenant routes share their paths with the tenant-less
                // internal endpoints, so they are registered first and win on tenant hosts.
                if ($this->hasTenantDomain()) {
                    $registerTenantRoutes();
                }

                // Internal JSON endpoints must be reachable with the tenant prefix too.
                $this->registerInternalRoutes($router);

                if (! $this->hasTenantDomain()) {
                    $registerTenantRoutes();
                }

                // A tenant-less entry point that redirects to the user's home. Skipped when a
                // page (e.g. the Dashboard) already lives at the panel root.
                $hasRootPage = ! $this->hasTenancy() && collect($this->getPages())->contains(static fn (string $page): bool => trim($page::getRoutePath(), '/') === '');

                if (! $hasRootPage) {
                    $router->get('/', PanelHomeController::class)->name('home')->middleware($this->getAuthMiddleware());
                }
            });
    }

    protected function registerInternalRoutes(Router $router): void
    {
        $prefix = trim((string) config('alfakit.system_route_prefix', '_alfakit'), '/');
        $groupStack = $router->getGroupStack();
        $currentPrefix = (string) ($groupStack[array_key_last($groupStack)]['prefix'] ?? '');
        $currentDomain = (string) ($groupStack[array_key_last($groupStack)]['domain'] ?? '');
        $name = $this->hasTenancy() && str_contains($currentPrefix.$currentDomain, '{tenant}') ? 'internal.tenant.' : 'internal.';

        // Token-based endpoints authorise themselves from the context token, so guest pages
        // (login, registration, password reset) can use them too.
        $router->prefix($prefix)->name($name)->group(static function (Router $router): void {
            $router->post('/actions', [ActionController::class, 'execute'])->name('actions.execute');
            // Field actions send the form's state, which can be too large for a query string, so the
            // modal endpoint also takes POST. One route (not a GET and a POST route on the same URI)
            // keeps Laravel Wayfinder's generated actions valid in starter kit apps.
            $router->match(['GET', 'HEAD', 'POST'], '/actions/modal', [ActionController::class, 'modal'])->name('actions.modal');
            $router->post('/schema/refresh', [SchemaController::class, 'refresh'])->name('schema.refresh');
            $router->get('/schema/options', [SchemaController::class, 'options'])->name('schema.options');
            $router->post('/schema/validate', [SchemaController::class, 'validate'])->name('schema.validate');
            $router->post('/schema/deferred', DeferredSchemaController::class)->name('schema.deferred');
            $router->post('/table/cell', [TableController::class, 'updateCell'])->name('table.cell');
            $router->post('/table/reorder', [TableController::class, 'reorder'])->name('table.reorder');
            $router->post('/table/columns', [TableController::class, 'reorderColumns'])->name('table.columns');
            // `Table::deferTotals()`: the count and summaries, loaded after the rows.
            $router->post('/table/totals', [TableController::class, 'totals'])->name('table.totals');
            // `Table::savedViews()`: save, share, rename, delete and pick a default view.
            $router->post('/table/views', [Http\Controllers\TableViewsController::class, 'handle'])->name('table.views');
            // `Table::spreadsheet()`: batch save (or dry-run validate) grid edits.
            $router->post('/table/cells', [TableController::class, 'updateCells'])->name('table.cells');
            $router->post('/locale', LocaleController::class)->name('locale');
            // `#[Expose]` methods of custom pages, called from their own frontend component.
            $router->post('/pages/call', [PageMethodController::class, 'call'])->name('pages.call');
            // `Action::undoable()`: the undo token authorises itself (see UndoController).
            $router->post('/actions/undo', [Http\Controllers\UndoController::class, 'undo'])->name('actions.undo');
            // `Dashboard::customizable()`: save or reset the user's layout.
            $router->post('/dashboard/layout', [Http\Controllers\DashboardLayoutController::class, 'update'])->name('dashboard.layout');
            // Edit pages: what changed since the form was loaded (`detectConflicts()`).
            $router->post('/conflicts', Http\Controllers\ConflictController::class)->name('conflicts');
        });

        // Global search queries resources, which are tenant-scoped, so on tenancy panels it
        // only exists under the `{tenant}` prefix.
        $withGlobalSearch = ! $this->hasTenancy() || $name === 'internal.tenant.';
        $withRealtime = $this->isRealtimeConfigured();

        $router->prefix($prefix)->name($name)->middleware($this->getAuthMiddleware())->group(static function (Router $router) use ($withGlobalSearch, $withRealtime): void {
            $router->post('/uploads', [UploadController::class, 'store'])->name('uploads.store');
            $router->delete('/uploads', [UploadController::class, 'destroy'])->name('uploads.destroy');
            // Resumable chunked uploads of files larger than `alfakit.uploads.chunk_size`.
            $router->post('/uploads/chunks', [UploadController::class, 'startChunked'])->name('uploads.chunks.start');
            $router->get('/uploads/chunks/{upload}', [UploadController::class, 'showChunked'])->name('uploads.chunks.show');
            $router->post('/uploads/chunks/{upload}', [UploadController::class, 'storeChunk'])->name('uploads.chunks.store');
            $router->post('/uploads/chunks/{upload}/complete', [UploadController::class, 'completeChunked'])->name('uploads.chunks.complete');
            $router->delete('/uploads/chunks/{upload}', [UploadController::class, 'destroyChunked'])->name('uploads.chunks.destroy');

            if ($withGlobalSearch) {
                $router->get('/global-search', GlobalSearchController::class)->name('global-search');
                // Command palette: "create" links and recent records (tenant-scoped like search).
                $router->get('/command-palette', Http\Controllers\CommandPaletteController::class)->name('command-palette');
            }

            // The user's customised keyboard shortcuts (per user and panel).
            $router->post('/shortcuts', Http\Controllers\KeyboardShortcutsController::class)->name('shortcuts.update');
            // `starterKit()`: the panel data of the drop-in header components on host pages.
            $router->get('/starter-kit', Http\Controllers\StarterKitController::class)->name('starter-kit');
            // `realtime()`: Echo authorises the panel's private and presence channels here, with
            // the panel's guard and (under `{tenant}`) the current tenant.
            if ($withRealtime) {
                $router->post('/broadcasting/auth', Http\Controllers\BroadcastingAuthController::class)->name('realtime.auth');
            }

            $router->get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
            $router->post('/notifications/read-all', [NotificationsController::class, 'markAllAsRead'])->name('notifications.read-all');
            $router->post('/notifications/{notification}/read', [NotificationsController::class, 'markAsRead'])->name('notifications.read');
            $router->delete('/notifications', [NotificationsController::class, 'destroyAll'])->name('notifications.destroy-all');

            // Signed links from import/export notifications; files are served to their owner only.
            $router->get('/exports/{export}/download', [ImportExportController::class, 'downloadExport'])->name('exports.download')->middleware('signed');
            $router->get('/imports/{import}/failed-rows', [ImportExportController::class, 'downloadFailedRows'])->name('imports.failed-rows')->middleware('signed');
            $router->get('/imports/example', [ImportExportController::class, 'downloadExample'])->name('imports.example')->middleware('signed');
        });

        // `pwa(push: true)`: the user's Web Push subscriptions.
        $router->prefix($prefix)->name($name)->middleware($this->getAuthMiddleware())->group(function (Router $router): void {
            $this->registerPwaPushRoutes($router);
        });
    }

    /**
     * Absolute URLs of the internal endpoints, for the frontend.
     *
     * @return array<string, string>
     */
    public function getEndpoints(): array
    {
        $prefix = $this->hasTenancy() && $this->getTenant() !== null ? 'internal.tenant.' : 'internal.';

        $endpoint = fn (string $name): string => $this->route($prefix.$name);
        $hasSearchRoute = ! $this->hasTenancy() || $this->getTenant() !== null;

        return array_filter([
            'action' => $endpoint('actions.execute'),
            'modal' => $endpoint('actions.modal'),
            'schemaRefresh' => $endpoint('schema.refresh'),
            'schemaOptions' => $endpoint('schema.options'),
            'schemaValidate' => $endpoint('schema.validate'),
            'schemaDeferred' => $endpoint('schema.deferred'),
            'tableCell' => $endpoint('table.cell'),
            'tableReorder' => $endpoint('table.reorder'),
            'tableColumns' => $endpoint('table.columns'),
            'tableTotals' => $endpoint('table.totals'),
            'tableViews' => $endpoint('table.views'),
            'tableCells' => $endpoint('table.cells'),
            'locale' => $endpoint('locale'),
            'pageCall' => $endpoint('pages.call'),
            'undo' => $endpoint('actions.undo'),
            'dashboardLayout' => $endpoint('dashboard.layout'),
            'conflicts' => $endpoint('conflicts'),
            'upload' => $endpoint('uploads.store'),
            'uploadDestroy' => $endpoint('uploads.destroy'),
            'uploadChunks' => $endpoint('uploads.chunks.start'),
            'globalSearch' => $hasSearchRoute ? $endpoint('global-search') : null,
            'commandPalette' => $hasSearchRoute ? $endpoint('command-palette') : null,
            'shortcuts' => $endpoint('shortcuts.update'),
            'notifications' => $endpoint('notifications.index'),
            'notificationsReadAll' => $endpoint('notifications.read-all'),
            'notificationsDestroyAll' => $endpoint('notifications.destroy-all'),
            'logout' => $this->getLogoutUrl(),
        ]);
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * Panel-level data shared with every Inertia page of this panel.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $user = $this->auth()->user();
        $tenant = $this->getTenant();

        $tenants = $user !== null && $this->hasTenancy() ? $this->getUserTenants($user) : [];

        return Serializable::compact([
            'id' => $this->id,
            'schemaContract' => SchemaContract::VERSION,
            'path' => $this->path,
            'url' => $this->safeUrl(),
            'brandName' => $this->getBrandName(),
            'brandLogo' => $this->evaluate($this->brandLogo),
            'darkModeBrandLogo' => $this->evaluate($this->darkModeBrandLogo),
            'brandLogoHeight' => $this->evaluate($this->brandLogoHeight),
            'favicon' => $this->getFavicon(),
            'colors' => $this->getThemeColorVariables() ?: null,
            'colorCss' => $this->getCustomColorCss(),
            'theme' => $this->getThemeSchema(),
            'slots' => $this->getSlotsSchema() ?: null,
            'darkMode' => Serializable::compact([
                'enabled' => $this->hasDarkMode(),
                'forced' => $this->hasDarkModeForced(),
                'default' => $this->getDefaultThemeMode() === ThemeMode::System ? null : $this->getDefaultThemeMode()->value,
                'switcher' => $this->hasThemeSwitcher() ? null : false,
            ]),
            'topbar' => $this->hasTopbar() ? null : false,
            'sidebarCollapsibleOnDesktop' => $this->isSidebarCollapsibleOnDesktop,
            'sidebarFullyCollapsibleOnDesktop' => $this->isSidebarFullyCollapsibleOnDesktop ?: null,
            'topNavigation' => $this->hasTopNavigation ?: null,
            'maxContentWidth' => $this->getMaxContentWidth(),
            'breadcrumbs' => $this->hasBreadcrumbs,
            'unsavedChangesAlerts' => $this->hasUnsavedChangesAlerts ?: null,
            // Navigation items link to tenant routes, so tenant-less pages of a tenancy panel
            // (profile, tenant registration, email verification) render without them.
            // `starterKit()`: the host sidebar renders the navigation from the shared
            // `alfakitNavigation` prop instead.
            'starterKit' => $this->isStarterKit ?: null,
            // Inertia page props serialise it split (structure once, state per request; see `HasInertiaProps`).
            'navigation' => $this->showsNavigation($user, $tenant) && ! $this->isSerializingSharedProps() ? array_map(static fn (NavigationGroup $group): array => $group->toSchema(), $this->applyNavigationGroupOptions($this->getNavigation())) : [],
            'user' => $user === null ? null : [
                'id' => $user->getAuthIdentifier(),
                'name' => $this->getUserName($user),
                'email' => $user->getAttribute('email'),
                'avatar' => $this->getUserAvatarUrl($user),
            ],
            'userMenu' => $user === null || ! $this->hasUserMenu ? null : [
                ...$this->getUserMenuSchema(),
                // Sidebar layouts: the menu moves to the top bar (`userMenu(position: Topbar)`).
                ...($this->getUserMenuPosition() === Support\Enums\UserMenuPosition::Topbar ? ['position' => 'topbar'] : []),
            ],
            'tenancy' => ! $this->hasTenancy() ? null : [
                'current' => $tenant === null ? null : [
                    'id' => $tenant->getKey(),
                    ...$this->getTenantNameSchema($tenant),
                    'label' => $this->getCurrentTenantLabel($tenant),
                    'avatar' => $this->getTenantAvatarUrl($tenant),
                    'url' => $this->getUrl($tenant),
                ],
                // `tenantSwitcher(false)`: the menu keeps the current tenant and its items only.
                'tenants' => $this->hasTenantSwitcher() ? array_map(fn (Model $item): array => [
                    'id' => $item->getKey(),
                    ...$this->getTenantNameSchema($item),
                    'avatar' => $this->getTenantAvatarUrl($item),
                    'url' => $this->getUrl($item),
                ], $tenants) : [],
                ...($this->hasTenantSwitcher() ? [] : ['switcher' => false]),
                ...($this->hasTenantSwitcher() && $this->isTenantMenuSearchable(count($tenants)) ? ['searchable' => true] : []),
                // Guests (guest access) see the current tenant but can't manage tenants.
                ...($tenantMenu = $user === null ? ['menu' => null] : $this->getTenantMenuSchema()),
                'menu' => $this->hasTenantMenu ? $tenantMenu['menu'] : null,
            ],
            'databaseNotifications' => ! $this->hasDatabaseNotifications || $user === null ? null : [
                'pollingInterval' => $this->evaluate($this->databaseNotificationsPolling),
                'unreadCount' => method_exists($user, 'unreadNotifications') ? Notifications\DatabaseNotificationScope::apply($user->unreadNotifications())->count() : 0,
                ...$this->getDatabaseNotificationsPositionSchema(),
            ],
            'globalSearch' => ! $this->hasGlobalSearch() || $user === null || ($this->hasTenancy() && $tenant === null) ? null : [
                'keyBindings' => $this->globalSearchKeyBindings,
                'placeholder' => __('alfakit::panel.global_search.placeholder'),
                ...$this->getGlobalSearchFieldSchema(),
                // Sidebar layouts: the field moves to the sidebar header.
                ...($this->getGlobalSearchPosition() === Support\Enums\GlobalSearchPosition::Sidebar ? ['position' => 'sidebar'] : []),
            ],
            'endpoints' => $this->getEndpoints(),
            // Files larger than this are uploaded in resumable chunks.
            'uploadChunkSize' => Support\ChunkedUpload::configuredChunkSize(),
            'auth' => [
                'login' => $this->getLoginUrl(),
                'register' => $this->getRegistrationUrl(),
                'passwordReset' => $this->getPasswordResetUrl(),
            ],
            'flashKey' => Notification::FLASH_KEY,
            'impersonation' => \AlfaSix\Alfakit\Auth\Impersonation\Impersonation::toSchema($this),
            'realtime' => $this->getRealtimeNotificationsSchema($user),
            'toastPosition' => $this->getToastPosition() === Support\Enums\ToastPosition::TopEnd ? null : $this->getToastPosition()->value,
            // Tenancy panels: only with a tenant (navigation, create links and records are tenant-scoped).
            'commandPalette' => $this->hasTenancy() && $tenant === null ? null : $this->getCommandPaletteSchema($user),
            'keyboardShortcuts' => $this->getKeyboardShortcutsSchema($user),
            // `pwa()`: service worker registration, install prompt and Web Push.
            'pwa' => $this->getPwaSchema($user),
            ...$this->getPanelOptionsSchema(),
        ]);
    }

    protected function safeUrl(): ?string
    {
        try {
            return $this->getUrl();
        } catch (Throwable) {
            return url($this->path);
        }
    }
}
