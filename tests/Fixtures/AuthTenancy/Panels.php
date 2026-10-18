<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Panel;
use Closure;
use Illuminate\Routing\Router;

/**
 * Registers a panel (and its routes) at test time.
 */
final class Panels
{
    public static function register(string $id, Closure $configure): Panel
    {
        $panel = $configure(Panel::make($id)->id($id)->path($id)->middleware(['web']));

        alfakit()->registerPanel($panel);

        $router = app(Router::class);
        $panel->registerRoutes($router);
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        return $panel;
    }
}
