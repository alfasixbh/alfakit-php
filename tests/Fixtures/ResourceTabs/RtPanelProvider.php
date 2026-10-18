<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * Relation manager tabs, combined content tabs, title-case labels and configurable
 * resources. Registered at runtime by `registerPanel()`.
 */
class RtPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('rt')
            ->path('rt')
            ->login()
            ->middleware(['web'])
            ->resources([
                RtPostResource::class,
                RtArchiveResource::class,
                RtArchiveResource::make('archived')->status('archived')->navigationLabel('Archived entries'),
                RtArchiveResource::make('drafts')->slug('draft-entries')->status('draft'),
            ]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('rt')) {
            return $manager->getPanel('rt');
        }

        $panel = (new self(app()))->panel(Panel::make('rt'));
        $manager->registerPanel($panel);

        $router = app(Router::class);
        $panel->registerRoutes($router);
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        return $panel;
    }
}
