<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Testing;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel for the `PageTester` tests, registered at runtime by `registerPanel()`.
 */
class HelpersPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('helpers')
            ->path('helpers')
            ->login()
            ->middleware(['web'])
            ->pages([HelperActionsPage::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('helpers')) {
            return $manager->getPanel('helpers');
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
