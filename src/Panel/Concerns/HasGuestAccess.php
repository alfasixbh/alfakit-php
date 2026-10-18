<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Http\Controllers\ActionController;
use AlfaSix\Alfakit\Http\Controllers\LocaleController;
use AlfaSix\Alfakit\Http\Controllers\PageMethodController;
use AlfaSix\Alfakit\Http\Controllers\PanelHomeController;
use AlfaSix\Alfakit\Http\Controllers\SchemaController;
use AlfaSix\Alfakit\Http\Controllers\TableController;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\Pages\Page as ResourcePage;
use AlfaSix\Alfakit\Resources\Resource;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Guest access: pages and resources that opt in (`protected static bool
 * $isGuestAccessible = true`) open without signing in. Everything else still redirects
 * guests to the login page. Authorisation (`canAccess()`, policies) runs with a `null`
 * user, so policy methods that accept guests take `?User $user`.
 *
 * ```php
 * $panel->guestAccess();
 * // per tenant (tenancy panels): only public teams
 * $panel->guestAccess(fn (?Team $tenant): bool => $tenant === null || $tenant->is_public);
 * ```
 */
trait HasGuestAccess
{
    protected bool|Closure $hasGuestAccess = false;

    /**
     * Let guests open the panel's guest-accessible pages. A closure receives `$tenant`
     * (null on tenant-less pages) on tenancy panels.
     */
    public function guestAccess(bool|Closure $condition = true): static
    {
        $this->hasGuestAccess = $condition;

        return $this;
    }

    /** Whether `guestAccess()` was configured at all (its closure may still say no). */
    public function isGuestAccessEnabled(): bool
    {
        return $this->hasGuestAccess !== false;
    }

    public function hasGuestAccess(?Model $tenant = null): bool
    {
        if ($this->hasGuestAccess === false) {
            return false;
        }

        return (bool) $this->evaluate($this->hasGuestAccess, ['tenant' => $tenant], $tenant === null ? [] : [Model::class => $tenant, $tenant::class => $tenant]);
    }

    /**
     * Whether a guest may open the given page (or a page of the given resource).
     *
     * @param  class-string  $class
     */
    public function isGuestAccessible(string $class, ?Model $tenant = null): bool
    {
        if (! $this->hasGuestAccess($tenant)) {
            return false;
        }

        if (is_a($class, Resource::class, true)) {
            $index = $class::getPages()['index'] ?? null;

            return ResourcePage::isResourceGuestAccessible($class)
                || ($index !== null && is_a($index->page, Page::class, true) && $index->page::isGuestAccessible());
        }

        return is_a($class, Page::class, true) && $class::isGuestAccessible();
    }

    /**
     * A tenant a guest may see: it exists and the panel's guest access allows it. Never
     * checks membership, so only guest-accessible pages may use it.
     */
    public function findTenantForGuest(mixed $key): ?Model
    {
        $model = $this->getTenantModel();

        if ($key instanceof Model && $model !== null && $key instanceof $model) {
            $key = $this->getTenantRouteKey($key);
        }

        if ($model === null || ! is_scalar($key) || $key === '') {
            return null;
        }

        $slugAttribute = $this->getTenantSlugAttribute();

        $tenant = $slugAttribute !== null
            ? $model::query()->where($slugAttribute, $key)->first()
            : $model::query()->find($key);

        return $tenant !== null && $this->hasGuestAccess($tenant) ? $tenant : null;
    }

    /**
     * Whether `Authenticate` lets a guest through to the request's route: a
     * guest-accessible page (of an existing, guest-visible tenant), the panel root of a
     * tenant-less panel, or a token-based internal endpoint (which checks the page itself
     * through `ResolvesContext`).
     */
    public function allowsGuestRequest(Request $request): bool
    {
        if (! $this->isGuestAccessEnabled()) {
            return false;
        }

        $route = $request->route();

        if ($route === null || ! is_object($route)) {
            return false;
        }

        $controller = $route->getControllerClass();

        if ($controller === null) {
            return false;
        }

        if (in_array($controller, [ActionController::class, SchemaController::class, TableController::class, LocaleController::class, PageMethodController::class], true)) {
            return $this->hasTenancy() && $route->parameter('tenant') !== null
                ? $this->findTenantForGuest($route->parameter('tenant')) !== null
                : $this->hasGuestAccess();
        }

        if ($controller === PanelHomeController::class) {
            return ! $this->hasTenancy() && $this->hasGuestAccess();
        }

        if (! is_a($controller, Page::class, true) || ! $controller::isGuestAccessible()) {
            return false;
        }

        $key = $this->hasTenancy() ? $route->parameter('tenant') : null;

        if ($key !== null) {
            return $this->findTenantForGuest($key) !== null;
        }

        return $this->hasGuestAccess();
    }
}
