<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages;

use AlfaSix\Alfakit\Panel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Returned by `Page::route('/path')` inside `Resource::getPages()`.
 */
final class PageRegistration
{
    /**
     * @param  class-string<Page>  $page
     */
    public function __construct(
        public readonly string $page,
        public readonly string $path,
    ) {}

    public function register(Panel $panel, string $name): Route
    {
        $route = RouteFacade::get($this->path, $this->page)->name($name);

        // Tenant billing: the page's (by default its resource's) `isTenantSubscriptionRequired()`.
        return $panel->hasTenantBilling()
            ? $panel->applyTenantSubscriptionMiddleware($route, $this->page::isTenantSubscriptionRequired($panel))
            : $route;
    }
}
