<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Auth\Impersonation\Impersonation;
use AlfaSix\Alfakit\Http\Controllers\Auth\LeaveImpersonationController;
use Illuminate\Routing\Router;

/**
 * The route that ends an impersonation started with `ImpersonateAction`, and the banner
 * data the renderers show meanwhile.
 */
trait HasImpersonation
{
    public function isImpersonating(): bool
    {
        return Impersonation::isImpersonating($this);
    }

    public function getLeaveImpersonationUrl(): string
    {
        return $this->route('auth.impersonation.leave');
    }

    protected function registerImpersonationRoutes(Router $router): void
    {
        $router->post('/impersonation/leave', LeaveImpersonationController::class)->name('auth.impersonation.leave');
    }
}
