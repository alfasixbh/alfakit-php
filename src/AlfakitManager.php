<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit;

use AlfaSix\Alfakit\Events\TenantSet;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

/**
 * Registry of panels plus the current request's panel and tenant context.
 */
class AlfakitManager
{
    /** @var array<string, Panel> */
    protected array $panels = [];

    protected ?Panel $currentPanel = null;

    protected ?Standalone\StandalonePanel $standalonePanel = null;

    protected ?AuthKit\AuthKitPanel $authKitPanel = null;

    protected ?Model $tenant = null;

    protected bool $isServing = false;

    /** @var array<Closure> */
    protected array $servingCallbacks = [];

    public function registerPanel(Panel $panel): void
    {
        $this->panels[$panel->getId()] = $panel;

        if ($panel->isDefault()) {
            foreach ($this->panels as $other) {
                if ($other !== $panel && $other->isDefault()) {
                    $other->default(false);
                }
            }
        }
    }

    /**
     * @return array<string, Panel>
     */
    public function getPanels(): array
    {
        return $this->panels;
    }

    public function getPanel(?string $id = null): Panel
    {
        if ($id === null) {
            return $this->getCurrentOrDefaultPanel();
        }

        if (! isset($this->panels[$id]) && $id === Standalone\StandalonePanel::ID && Standalone\StandalonePanel::isEnabled()) {
            return $this->getStandalonePanel();
        }

        if (! isset($this->panels[$id]) && $id === AuthKit\AuthKitPanel::ID) {
            return $this->getAuthKitPanel();
        }

        return $this->panels[$id] ?? throw new InvalidArgumentException("Panel [{$id}] is not registered.");
    }

    /**
     * The hidden panel serving components used outside panels (`alfakit.standalone`). It is
     * not listed in `getPanels()` and never becomes the default panel.
     */
    public function getStandalonePanel(): Standalone\StandalonePanel
    {
        return $this->standalonePanel ??= Standalone\StandalonePanel::make();
    }

    /**
     * The hidden panel behind the app auth kit (`AuthKit::configure()` / `AuthKit::routes()`),
     * created on first use. Like the standalone panel it is not listed in `getPanels()`.
     */
    public function getAuthKitPanel(): AuthKit\AuthKitPanel
    {
        return $this->authKitPanel ??= AuthKit\AuthKitPanel::make();
    }

    public function hasAuthKit(): bool
    {
        return $this->authKitPanel !== null;
    }

    public function hasPanel(string $id): bool
    {
        return isset($this->panels[$id]);
    }

    public function getDefaultPanel(): ?Panel
    {
        foreach ($this->panels as $panel) {
            if ($panel->isDefault()) {
                return $panel;
            }
        }

        return array_values($this->panels)[0] ?? null;
    }

    public function setCurrentPanel(?Panel $panel): void
    {
        $this->currentPanel = $panel;
    }

    public function getCurrentPanel(): ?Panel
    {
        return $this->currentPanel;
    }

    public function getCurrentOrDefaultPanel(): Panel
    {
        if ($this->currentPanel !== null) {
            return $this->currentPanel;
        }

        $default = $this->getDefaultPanel();

        // Applications that only use components outside panels have no panel of their own.
        if ($default === null && Standalone\StandalonePanel::isEnabled()) {
            return $this->getStandalonePanel();
        }

        return $default
            ?? throw new RuntimeException('No Alfakit panel has been registered. Create one with `php artisan make:alfakit-panel`.');
    }

    public function setTenant(?Model $tenant, bool $isQuiet = false): void
    {
        $this->tenant = $tenant;

        if ($tenant !== null && ! $isQuiet) {
            TenantSet::dispatch($tenant, $this->auth());
        }
    }

    /**
     * Forget everything tied to one request (current panel, tenant, serving state). Called
     * at the start of every panel request and when the request terminates, so long-lived
     * workers such as Octane never leak one request's tenant into the next.
     */
    public function resetRequestState(): void
    {
        $this->currentPanel = null;
        $this->tenant = null;
        $this->isServing = false;
    }

    public function getTenant(): ?Model
    {
        return $this->tenant;
    }

    public function auth(): ?Authenticatable
    {
        return $this->currentPanel?->auth()->user() ?? $this->getDefaultPanel()?->auth()->user();
    }

    /**
     * Register a callback to run on every panel request, after the panel is set up.
     */
    public function serving(Closure $callback): void
    {
        $this->servingCallbacks[] = $callback;
    }

    public function markAsServing(): void
    {
        $this->isServing = true;

        foreach ($this->servingCallbacks as $callback) {
            $callback($this->currentPanel);
        }
    }

    public function isServing(): bool
    {
        return $this->isServing;
    }

    // ---------------------------------------------------------------- Localization

    /**
     * Currency for money formatting: the current panel's, or the app default outside a panel.
     */
    public function getCurrency(): string
    {
        return $this->currentPanel?->getCurrency() ?? Support\Currency::default();
    }

    /**
     * Timezone dates are shown and entered in: the current panel's, or the app timezone.
     */
    public function getTimezone(): string
    {
        return $this->currentPanel?->getTimezone() ?? (string) config('app.timezone', 'UTC');
    }

    public function getCurrentLocale(): Localization\Locale
    {
        return $this->currentPanel?->getCurrentLocale() ?? Localization\Locale::make(app()->getLocale());
    }

    /**
     * Render one of the application's Inertia pages with Alfakit components as props, outside
     * any panel. Props may be built components (`OrdersTable::make([...])`), standalone
     * component classes (built with `make()`), `[Class::class, [parameters]]` pairs, or any
     * other value, passed through as is:
     *
     * ```php
     * return Alfakit::render('orders', [
     *     'stats' => OrderStats::class,
     *     'orders' => [OrdersTable::class, ['customer' => $customer]],
     *     'title' => __('Orders'),
     * ]);
     * ```
     *
     * @param  array<string, mixed>  $props
     */
    public function render(string $component, array $props = []): \Inertia\Response
    {
        return \Inertia\Inertia::render($component, array_map(static function (mixed $value): mixed {
            if (is_string($value) && is_subclass_of($value, Standalone\Component::class)) {
                return $value::make();
            }

            if (is_array($value) && array_is_list($value) && count($value) === 2 && is_string($value[0]) && is_subclass_of($value[0], Standalone\Component::class) && is_array($value[1])) {
                return $value[0]::make($value[1]);
            }

            return $value;
        }, $props));
    }

    /**
     * Test helpers for a page, driven through the panel's HTTP endpoints (Pest/PHPUnit).
     *
     * @param  class-string<Pages\Page>|string  $page  A page class or a URL
     * @param  array<string, mixed>  $parameters  Route parameters (`record`, `tenant`…)
     */
    public function testPage(string $page, array $parameters = [], ?string $panel = null): Testing\PageTester
    {
        return Testing\PageTester::make($page, $parameters, $panel);
    }
}
