<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Billing\Providers\Contracts\BillingProvider;
use Closure;

class FakeBillingProvider implements BillingProvider
{
    public function getRouteAction(): string|Closure|array
    {
        return fn () => response('portal for '.alfakit()->getTenant()?->getAttribute('slug'));
    }

    public function getSubscribedMiddleware(): string
    {
        return RequireSubscription::class;
    }
}
