<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Billing\Providers\Contracts\BillingProvider;
use AlfaSix\Alfakit\Http\Middleware\AuthorizeTenantBilling;
use AlfaSix\Alfakit\Http\Middleware\RequireTenantSubscription;
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

    protected bool|Closure|null $authorizeTenantBillingUsing = null;

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

    /**
     * Who may manage the tenant's billing (`fn (User $user, Model $tenant): bool`): the
     * others don't see the Billing entry of the tenant menu. Without it every member of the
     * tenant may. Billing providers check `canManageTenantBilling()` on their routes.
     */
    public function authorizeTenantBilling(bool|Closure|null $callback = true): static
    {
        $this->authorizeTenantBillingUsing = $callback;

        return $this;
    }

    public function canManageTenantBilling(): bool
    {
        if ($this->authorizeTenantBillingUsing === null) {
            return true;
        }

        if (! $this->authorizeTenantBillingUsing instanceof Closure) {
            return $this->authorizeTenantBillingUsing;
        }

        $user = $this->auth()->user();

        return $user !== null && (bool) $this->evaluate($this->authorizeTenantBillingUsing, ['user' => $user, 'tenant' => $this->getTenant()]);
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

    /**
     * A closure is asked with the current tenant (null while routes are registered).
     */
    public function isTenantSubscriptionRequired(): bool
    {
        return $this->hasTenantBilling() && (bool) $this->evaluate($this->isTenantSubscriptionRequired, ['tenant' => $this->getTenant()]);
    }

    public function getTenantSubscribedMiddleware(): ?string
    {
        $middleware = $this->hasTenantBilling() ? $this->tenantBillingProvider?->getSubscribedMiddleware() : null;

        return is_string($middleware) && $middleware !== '' ? $middleware : null;
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

        // A closure is asked on every request (`RequireTenantSubscription`), where the
        // tenant is known: a page or resource that decides for itself overrides it.
        if ($this->isTenantSubscriptionRequired instanceof Closure) {
            // The same answer as the panel gives right now: the page follows the panel.
            if ($required === $this->isTenantSubscriptionRequired()) {
                return $route;
            }

            return $required ? $route->middleware($middleware) : $route->withoutMiddleware($this->getTenantBillingMiddleware());
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
        if (! $this->hasTenantBilling()) {
            return [];
        }

        if ($this->isTenantSubscriptionRequired instanceof Closure) {
            return [RequireTenantSubscription::class.':'.$this->getId()];
        }

        return $this->isTenantSubscriptionRequired ? [(string) $this->tenantBillingProvider?->getSubscribedMiddleware()] : [];
    }

    /**
     * @return array<MenuItem>
     */
    protected function getTenantBillingMenuItems(): array
    {
        $url = $this->canManageTenantBilling() ? $this->getTenantBillingUrl() : null;

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

        $route = $router->get('/'.$this->tenantBillingRouteSlug, $this->tenantBillingProvider->getRouteAction())->name('tenant.billing')
            // `authorizeTenantBilling()` on the route itself, whatever the provider does.
            ->middleware(AuthorizeTenantBilling::class.':'.$this->getId());

        // Unsubscribed tenants must still reach the page where they subscribe.
        $middleware = $this->getTenantBillingMiddleware();

        if ($middleware !== []) {
            $route->withoutMiddleware($middleware);
        }
    }
}
