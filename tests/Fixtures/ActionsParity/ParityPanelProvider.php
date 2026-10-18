<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsParity;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel for the nice-to-have action, notification, widget and tester features,
 * registered at runtime by `registerPanel()`.
 */
class ParityPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('parity')
            ->path('parity')
            ->login()
            ->middleware(['web'])
            ->pages([ParityActionsPage::class, ParityTablePage::class, ParityDashboard::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('parity')) {
            return $manager->getPanel('parity');
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
