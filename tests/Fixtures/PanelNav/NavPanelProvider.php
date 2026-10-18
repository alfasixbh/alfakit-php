<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Support\Enums\Width;
use Illuminate\Routing\Router;

/**
 * A panel for clusters, sub-navigation, layout components and panel options. Registered at
 * runtime by `NavPanelProvider::registerPanel()` in the tests that use it.
 */
class NavPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('nav')
            ->path('nav')
            ->login()
            ->middleware(['web'])
            ->pages([Settings::class, GeneralSettings::class, BillingSettings::class, ElementsPage::class])
            ->resources([TagResource::class, NoteResource::class])
            ->navigationGroups([NavigationGroup::make('Content')->collapsed()])
            ->collapsibleNavigationGroups(false)
            ->sidebarWidth('20rem')
            ->collapsedSidebarWidth('4rem; color: red')
            ->simplePageMaxContentWidth(Width::Large)
            ->spaUrlExceptions(fn (): array => [url('/nav/reports'), '*/exports/*'])
            ->registerErrorNotification('Not allowed', 'Ask an admin.', 403)
            ->databaseTransactions();
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('nav')) {
            return $manager->getPanel('nav');
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
