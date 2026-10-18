<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;
use Illuminate\Routing\Router;

/**
 * Extra routes inside the panel's route group (its path, `alfakit.{id}.` name prefix and
 * panel middleware), like Filament's `->routes()` and `->authenticatedRoutes()`:
 *
 * ```php
 * $panel->routes(fn (Router $router) => $router->get('/invitations/{user}', AcceptInvitation::class)
 *     ->middleware('signed')->name('auth.invitation.accept'));
 * $panel->authenticatedRoutes(fn () => Route::get('/reports/export', ExportReportsController::class)->name('reports.export'));
 * ```
 *
 * Closures receive `$router` and `$panel`. `routes()` are open to guests (add `guest:…`
 * middleware yourself where it matters); `authenticatedRoutes()` sit behind the panel's
 * auth middleware and, on tenancy panels, under the tenant prefix.
 */
trait HasCustomRoutes
{
    /** @var array<int, Closure> */
    protected array $customRoutes = [];

    /** @var array<int, Closure> */
    protected array $authenticatedCustomRoutes = [];

    public function routes(?Closure $routes): static
    {
        if ($routes !== null) {
            $this->customRoutes[] = $routes;
        }

        return $this;
    }

    public function authenticatedRoutes(?Closure $routes): static
    {
        if ($routes !== null) {
            $this->authenticatedCustomRoutes[] = $routes;
        }

        return $this;
    }

    protected function registerCustomRoutes(Router $router, bool $authenticated): void
    {
        foreach ($authenticated ? $this->authenticatedCustomRoutes : $this->customRoutes as $routes) {
            $this->evaluate($routes, ['router' => $router, 'panel' => $this], [Router::class => $router, static::class => $this]);
        }
    }
}
