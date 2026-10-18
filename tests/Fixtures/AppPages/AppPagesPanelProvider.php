<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AppPages;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Routing\Router;

/**
 * A tenant panel with a custom app page (`#[Expose]` methods, named schema pieces, the
 * fullscreen layout). Registered at runtime by `registerPanel()`.
 */
class AppPagesPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('apps')
            ->path('apps')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->pages([BoardPage::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('apps')) {
            return $manager->getPanel('apps');
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
