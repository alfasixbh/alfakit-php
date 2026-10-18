<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3;

use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Support\Assets\Css;
use AlfaSix\Alfakit\Support\Enums\GlobalSearchPosition;
use AlfaSix\Alfakit\Support\Enums\UserMenuPosition;
use Illuminate\Routing\Router;

/**
 * Deferred schemas, relation manager options and panel options of the third parity
 * round. Registered at runtime by `registerPanel()`.
 */
class P3PanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('p3')
            ->path('p3')
            ->login()
            ->middleware(['web'])
            ->resources([P3PostResource::class, P3PostCommentResource::class])
            ->pages([
                P3SettingsPage::make('general')->category('general'),
                P3SettingsPage::make('advanced')->slug('advanced-settings')->category('advanced'),
            ])
            ->breadcrumbs(hasNavigationHierarchy: true)
            ->subNavigationPosition(SubNavigationPosition::End)
            ->globalSearch(position: GlobalSearchPosition::Sidebar)
            ->userMenu(position: UserMenuPosition::Topbar)
            ->assets([Css::make('p3-panel', 'https://cdn.example.com/p3.css')]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('p3')) {
            return $manager->getPanel('p3');
        }

        $panel = (new self(app()))->panel(Panel::make('p3'));
        $manager->registerPanel($panel);

        $router = app(Router::class);
        $panel->registerRoutes($router);
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        return $panel;
    }
}
