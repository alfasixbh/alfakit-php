<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Billing\Providers\Contracts;

use Closure;

/**
 * Connects tenant billing (Laravel Cashier, Spark, Paddle…) to a panel with
 * `->tenantBillingProvider(new MyBillingProvider)`.
 */
interface BillingProvider
{
    /**
     * The action of the tenant's billing route (`/{tenant}/billing`): a controller class,
     * `[Controller::class, 'method']` or a closure, usually redirecting to the billing portal.
     *
     * @return string|Closure|array<int, string>
     */
    public function getRouteAction(): string|Closure|array;

    /**
     * Middleware that lets subscribed tenants through and sends the others to billing,
     * applied to tenant routes with `->requiresTenantSubscription()`.
     */
    public function getSubscribedMiddleware(): string;
}
