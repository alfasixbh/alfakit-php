<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel for the action and resource feature tests (wizard/nested/rate-limited actions,
 * list tabs, nested and singular resources, relation groups). Registered at runtime by
 * `ArPanelProvider::register()` in the tests that use it.
 */
class ArPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('ar')
            ->path('ar')
            ->login()
            ->middleware(['web'])
            ->resources([ArticleResource::class, ArticleCommentResource::class, SettingsResource::class, AuthorResource::class])
            ->pages([ActionsPage::class])
            ->globalSearch();
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('ar')) {
            return $manager->getPanel('ar');
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
