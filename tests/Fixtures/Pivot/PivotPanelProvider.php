<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel for BelongsToMany relation managers with pivot attributes. Registered at
 * runtime by `PivotPanelProvider::registerPanel()`.
 */
class PivotPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('pivot')
            ->path('pivot')
            ->login()
            ->middleware(['web'])
            ->resources([CourseResource::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('pivot')) {
            return $manager->getPanel('pivot');
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
