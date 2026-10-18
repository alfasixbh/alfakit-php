<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Billing\Providers\Contracts\BillingProvider;
use AlfaSix\Alfakit\Navigation\MenuItem;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;

/**
 * Tenant billing: a billing route per tenant handled by the provider, a "Billing" entry in
 * the tenant menu, and (with `requiresTenantSubscription()`) the provider's middleware on
 * every tenant route and internal request.
 */
trait HasTenantBilling
{
    protected ?BillingProvider $tenantBillingProvider = null;

    protected bool|Closure $isTenantSubscriptionRequired = false;

    protected string $tenantBillingRouteSlug = 'billing';

    public function tenantBillingProvider(?BillingProvider $provider): static
    {
        $this->tenantBillingProvider = $provider;

        return $this;
    }

    public function requiresTenantSubscription(bool|Closure $condition = true): static
    {
        $this->isTenantSubscriptionRequired = $condition;

        return $this;
    }

    public function tenantBillingRouteSlug(string $slug): static
    {
        $this->tenantBillingRouteSlug = trim($slug, '/');

        return $this;
    }

    public function getTenantBillingRouteSlug(): string
    {
        return $this->tenantBillingRouteSlug;
    }

    public function getTenantBillingProvider(): ?BillingProvider
    {
        return $this->tenantBillingProvider;
    }

    public function hasTenantBilling(): bool
    {
        return $this->hasTenancy() && $this->tenantBillingProvider !== null;
    }

    public function isTenantSubscriptionRequired(): bool
    {
        return $this->hasTenantBilling() && (bool) $this->evaluate($this->isTenantSubscriptionRequired);
    }

    /**
     * Give `$route` the billing provider's subscribed middleware when `$required`, take it
     * off when not (per page and resource `isTenantSubscriptionRequired()`).
     */
    public function applyTenantSubscriptionMiddleware(\Illuminate\Routing\Route $route, bool $required): \Illuminate\Routing\Route
    {
        $middleware = $this->hasTenantBilling() ? $this->tenantBillingProvider?->getSubscribedMiddleware() : null;

        if (! is_string($middleware) || $middleware === '') {
            return $route;
        }

        $panelRequires = (bool) $this->evaluate($this->isTenantSubscriptionRequired);

        if ($required && ! $panelRequires) {
            $route->middleware($middleware);
        } elseif (! $required && $panelRequires) {
            $route->withoutMiddleware($middleware);
        }

        return $route;
    }

    public function getTenantBillingUrl(?Model $tenant = null): ?string
    {
        $tenant ??= $this->getTenant();

        if (! $this->hasTenantBilling() || $tenant === null) {
            return null;
        }

        return $this->route('tenant.billing', ['tenant' => $tenant]);
    }

    /**
     * @return array<string>
     */
    protected function getTenantBillingMiddleware(): array
    {
        return $this->hasTenantBilling() && $this->evaluate($this->isTenantSubscriptionRequired)
            ? [(string) $this->tenantBillingProvider?->getSubscribedMiddleware()]
            : [];
    }

    /**
     * @return array<MenuItem>
     */
    protected function getTenantBillingMenuItems(): array
    {
        $url = $this->getTenantBillingUrl();

        return $url === null ? [] : [MenuItem::make()->label(__('alfakit::tenancy.billing'))->icon('credit-card')->url($url)];
    }

    /**
     * Registered inside the tenant route group.
     */
    protected function registerTenantBillingRoutes(Router $router): void
    {
        if (! $this->hasTenantBilling() || $this->tenantBillingProvider === null) {
            return;
        }

        $route = $router->get('/'.$this->tenantBillingRouteSlug, $this->tenantBillingProvider->getRouteAction())->name('tenant.billing');

        // Unsubscribed tenants must still reach the page where they subscribe.
        $middleware = $this->getTenantBillingMiddleware();

        if ($middleware !== []) {
            $route->withoutMiddleware($middleware);
        }
    }
}
