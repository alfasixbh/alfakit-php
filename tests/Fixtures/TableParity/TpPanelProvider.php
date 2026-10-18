<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\TableParity;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel whose single resource lets each test define the table
 * (`TpPostResource::$table`), for the table parity endpoint tests.
 */
class TpPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tp')
            ->path('tp')
            ->login()
            ->middleware(['web'])
            ->resources([TpPostResource::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('tp')) {
            return $manager->getPanel('tp');
        }

        $panel = (new self(app()))->panel(Panel::make());
        $manager->registerPanel($panel);

        $router = app(Router::class);
        $panel->registerRoutes($router);
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        return $panel;
    }
}
