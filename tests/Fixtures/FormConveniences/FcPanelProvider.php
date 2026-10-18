<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormConveniences;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel for text input conveniences, inline labels and wizard actions. Registered at
 * runtime by `registerPanel()`.
 */
class FcPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('fc')
            ->path('fc')
            ->login()
            ->middleware(['web'])
            ->pages([FcPage::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('fc')) {
            return $manager->getPanel('fc');
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
