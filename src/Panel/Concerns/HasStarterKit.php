<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Auth\Impersonation\Impersonation;
use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Http\Middleware\SetPanelLocale;
use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Notifications\DatabaseNotificationScope;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

/**
 * Starter kit mode: the panel runs inside an application made from Laravel's React, Vue or
 * Svelte starter kit instead of as a separate admin area.
 *
 * - Authentication is the host's (Fortify, the `web` guard): Alfakit's login, registration,
 *   password reset and profile pages are off, and guests are sent to the host's `login` route.
 * - Pages render through the host's root view (`app`) and inside the host's own layout
 *   (sidebar, header, appearance) instead of Alfakit's chrome; Alfakit does not touch the
 *   document's theme, so the host's shadcn tokens and dark mode apply.
 * - The panel navigation is shared with every Inertia page of the application as the
 *   `alfakitNavigation` prop, for the host's sidebar, with what the drop-in header
 *   components (`<AlfakitSearch />`, `<AlfakitNotifications />`, `<AlfakitTenantSwitcher />`,
 *   `<AlfakitLanguageSwitcher />`, and plugins' own such as `<AlfakitCopilot />`) need before
 *   they load (`widgets`).
 * - The panel's dashboard widgets are shared with the host's dashboard (`alfakitWidgets`,
 *   `starterKitDashboard()`), for the drop-in `<AlfakitWidgets />`.
 * - Multi-factor authentication is the host's (Fortify's two-factor settings): Alfakit's
 *   `multiFactorAuthentication()` is ignored, its challenge belongs to Alfakit's login page.
 *
 * ```php
 * $panel->id('admin')->path('admin')->starterKit()->middleware(['web']);
 * ```
 */
trait HasStarterKit
{
    protected bool $isStarterKit = false;

    protected string $starterKitRootView = 'app';

    protected string $starterKitLoginRoute = 'login';

    /** @var array<string>|null The host routes that show the panel's widgets (`<AlfakitWidgets />`). */
    protected ?array $starterKitDashboardRoutes = ['dashboard'];

    /** @var array<string, Closure> Plugins' header components (`starterKitWidget()`), by name. */
    protected array $starterKitWidgets = [];

    /**
     * Run the panel inside a Laravel starter kit app: the host's authentication, root view and
     * layout. Turns Alfakit's own login, registration, password reset and profile pages off
     * (call those methods after this one to bring one back).
     *
     * @param  string  $rootView  The host's Inertia root view.
     * @param  string  $loginRoute  The route guests are redirected to (Fortify's `login`).
     */
    public function starterKit(bool $condition = true, string $rootView = 'app', string $loginRoute = 'login'): static
    {
        $this->isStarterKit = $condition;
        $this->starterKitRootView = $rootView;
        $this->starterKitLoginRoute = $loginRoute;

        if ($condition) {
            $this->hasLogin = false;
            $this->hasRegistration = false;
            $this->hasPasswordReset = false;
            $this->hasProfile = false;
        }

        return $this;
    }

    /**
     * The host pages that show the panel's dashboard widgets (`<AlfakitWidgets />` on the
     * starter kit's `/dashboard`), by route name or pattern (`dashboard`, `teams.*`), or
     * `false` for none. The widgets are shared as the `alfakitWidgets` prop on those pages
     * only.
     *
     * @param  string|array<string>|false  $routes
     */
    public function starterKitDashboard(string|array|false $routes = 'dashboard'): static
    {
        $this->starterKitDashboardRoutes = $routes === false ? null : array_values((array) $routes);

        return $this;
    }

    /**
     * @return array<string>|null
     */
    public function getStarterKitDashboardRoutes(): ?array
    {
        return $this->starterKitDashboardRoutes === [] ? null : $this->starterKitDashboardRoutes;
    }

    /**
     * The panel's dashboard widgets for the current host page (`alfakitWidgets` prop): a
     * standalone widgets payload bound to the panel, or null on other pages, for guests,
     * users who may not access the panel, tenancy panels (their widgets are tenant-scoped)
     * and panels without widgets.
     *
     * @return array<string, mixed>|null
     */
    public function getStarterKitDashboardSchema(): ?array
    {
        $routes = $this->getStarterKitDashboardRoutes();

        if (! $this->isStarterKit || $routes === null || ! request()->routeIs(...$routes)) {
            return null;
        }

        return \AlfaSix\Alfakit\Standalone\StarterKitDashboard::payloadFor($this);
    }

    /**
     * Data of a plugin's own drop-in header component, shared with every page of the host
     * application in `alfakitNavigation.widgets.{name}` (what it renders before its code
     * loads). The closure receives `$user` and `$tenant` (null on the host's own pages of a
     * tenancy panel) and returns the data, or null to render nothing. The AI plugin uses
     * it for `<AlfakitCopilot />`:
     *
     * ```php
     * $panel->starterKitWidget('copilot', fn (): ?array => ['label' => 'Open AI copilot', 'keyBindings' => ['mod+j']]);
     * ```
     */
    public function starterKitWidget(string $name, Closure $data): static
    {
        $this->starterKitWidgets[$name] = $data;

        return $this;
    }

    /** Alias of `starterKit()`: the panel is embedded in the host application. */
    public function embedded(bool $condition = true, string $rootView = 'app', string $loginRoute = 'login'): static
    {
        return $this->starterKit($condition, $rootView, $loginRoute);
    }

    public function isStarterKit(): bool
    {
        return $this->isStarterKit;
    }

    public function getStarterKitRootView(): string
    {
        return $this->starterKitRootView;
    }

    public function getStarterKitLoginRoute(): string
    {
        return $this->starterKitLoginRoute;
    }

    /** The host's login page, when its route exists. */
    public function getStarterKitLoginUrl(): ?string
    {
        return Route::has($this->starterKitLoginRoute) ? route($this->starterKitLoginRoute) : null;
    }

    /**
     * The panel navigation for the host's sidebar (`alfakitNavigation` prop), built as the
     * panel even on the host's own pages, plus the drop-in header components' data
     * (`widgets`). Null for guests and users who may not access the panel. Tenancy panels
     * outside a tenant share no navigation groups (their items link to tenant routes) but
     * keep the tenant switcher and notifications.
     *
     * @return array<string, mixed>|null
     */
    public function getStarterKitNavigationSchema(): ?array
    {
        $user = $this->auth()->user();

        if ($user === null || ! $this->hasNavigation()) {
            return null;
        }

        if ($user instanceof CanAccessPanel && ! $user->canAccessPanel($this)) {
            return null;
        }

        $manager = alfakit();
        $previous = $manager->getCurrentPanel();

        // Resource URLs, policies and navigation closures resolve against the current panel.
        if ($previous !== $this) {
            $manager->setCurrentPanel($this);
            $this->boot();
        }

        try {
            $build = function () use ($user): array {
                $tenant = $this->hasTenancy() ? $this->getTenant() : null;

                return Serializable::compact([
                    'id' => $this->getId(),
                    'url' => $this->safeUrl() ?? url($this->getPath()),
                    'brandName' => $this->getBrandName(),
                    'groups' => $this->hasTenancy() && $tenant === null ? [] : array_values(array_map(
                        static fn (NavigationGroup $group): array => $group->toSchema(),
                        $this->applyNavigationGroupOptions($this->getNavigation()),
                    )),
                    'widgets' => $this->getStarterKitWidgetsSchema($user, $tenant),
                ]);
            };

            // On the host's own pages the labels follow the panel's language (as on its pages
            // and in the header components), while the host page keeps its own.
            return $previous === $this ? $build() : SetPanelLocale::using($this, $build);
        } finally {
            if ($previous !== $this) {
                $manager->setCurrentPanel($previous);
            }
        }
    }

    /**
     * What the drop-in header components render before their code and the panel data load:
     * the search field's key bindings and placeholder, the unread count of the bell, the
     * tenant switcher's tenants and the current language of the language switcher. Everything else (translations, endpoints, the command
     * palette, realtime) comes from `endpoint` once a component renders.
     *
     * Null while the panel's routes are not registered.
     *
     * @return array<string, mixed>|null
     */
    protected function getStarterKitWidgetsSchema(Authenticatable $user, ?Model $tenant): ?array
    {
        $endpoint = ($tenant !== null ? 'internal.tenant.' : 'internal.').'starter-kit';

        if (! Route::has($this->generateRouteName($endpoint))) {
            return null;
        }

        // Search and the palette are tenant-scoped, like the panel's own (see `toSchema()`).
        $hasSearch = ! $this->hasTenancy() || $tenant !== null;
        $hasGlobalSearch = $hasSearch && $this->hasGlobalSearch();
        $tenants = $this->hasTenancy() ? $this->getUserTenants($user) : [];

        return Serializable::compact([
            'endpoint' => $this->route($endpoint),
            'search' => $hasGlobalSearch || ($hasSearch && $this->hasCommandPalette()) ? [
                'keyBindings' => $hasGlobalSearch ? $this->globalSearchKeyBindings : ['mod+k'],
                'placeholder' => $hasGlobalSearch ? __('alfakit::panel.global_search.placeholder') : __('alfakit::panel.command_palette.placeholder'),
            ] : null,
            'notifications' => $this->hasDatabaseNotifications() ? [
                'label' => __('alfakit::ui.notifications'),
                'unreadCount' => method_exists($user, 'unreadNotifications') ? DatabaseNotificationScope::apply($user->unreadNotifications())->count() : 0,
            ] : null,
            'tenancy' => $this->hasTenancy() ? Serializable::compact([
                'current' => $tenant === null ? null : [
                    'id' => $tenant->getKey(),
                    ...$this->getTenantNameSchema($tenant),
                    'label' => $this->getCurrentTenantLabel($tenant),
                    'avatar' => $this->getTenantAvatarUrl($tenant),
                    'url' => $this->getUrl($tenant),
                ],
                'tenants' => array_map(fn (Model $item): array => [
                    'id' => $item->getKey(),
                    ...$this->getTenantNameSchema($item),
                    'avatar' => $this->getTenantAvatarUrl($item),
                    'url' => $this->getUrl($item),
                ], $tenants),
                'registrationUrl' => $this->getTenantRegistrationUrl(),
                'registrationLabel' => $this->getTenantRegistrationUrl() === null ? null : $this->getTenantRegistrationLabel(),
                'searchable' => $this->isTenantMenuSearchable(count($tenants)) ? true : null,
            ]) : null,
            // `<AlfakitLanguageSwitcher />`: the current language, with two or more to choose from.
            'language' => $this->hasLocaleSwitcher() && count($this->getLocales()) > 1 ? [
                'label' => __('alfakit::ui.change_language'),
                'current' => $this->getCurrentLocale()->toSchema(),
            ] : null,
            // While impersonating: the banner with **Leave** on the host's own pages too.
            'impersonation' => Impersonation::toSchema($this),
            // Plugins' header components (`starterKitWidget()`); the built-in ones win.
            ...array_diff_key(
                array_map(fn (Closure $data): mixed => $data($user, $tenant), $this->starterKitWidgets),
                array_flip(['endpoint', 'search', 'notifications', 'tenancy', 'language', 'impersonation']),
            ),
        ]);
    }
}
