<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Http\Controllers\Auth\EmailChangeVerificationController;
use Illuminate\Routing\Router;

/**
 * `->emailChangeVerification()`: a new email address entered on the profile page is only
 * applied once the link sent to it is clicked; the old address is told about the request.
 */
trait HasEmailChangeVerification
{
    protected bool $hasEmailChangeVerification = false;

    public function emailChangeVerification(bool $condition = true): static
    {
        $this->hasEmailChangeVerification = $condition;

        return $this;
    }

    public function hasEmailChangeVerification(): bool
    {
        return $this->hasEmailChangeVerification;
    }

    protected function registerEmailChangeVerificationRoutes(Router $router): void
    {
        if (! $this->hasEmailChangeVerification) {
            return;
        }

        $router->get($this->getEmailChangeVerificationRoutePath().'/{id}/{token}', [EmailChangeVerificationController::class, 'verify'])
            ->name('auth.email-change-verification.verify')
            ->middleware(['signed', 'throttle:6,1']);

        $router->get($this->getEmailChangeBlockRoutePath().'/{id}/{token}', [EmailChangeVerificationController::class, 'block'])
            ->name('auth.email-change-verification.block')
            ->middleware(['signed', 'throttle:6,1']);
    }
}
