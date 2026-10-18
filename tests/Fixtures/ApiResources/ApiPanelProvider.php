<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use Illuminate\Routing\Router;

/**
 * Panels for API resources: `api` (no tenancy) and `apiteams` (tenant `Team`). Registered
 * at runtime by `ApiPanelProvider::registerPanels()` in the tests that use them.
 */
class ApiPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('api')
            ->path('api')
            ->login()
            ->middleware(['web'])
            ->globalSearch()
            ->resources([SupplierResource::class, CarrierResource::class]);
    }

    public function tenantPanel(Panel $panel): Panel
    {
        return $panel
            ->id('apiteams')
            ->path('apiteams')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->resources([SupplierResource::class, CarrierResource::class]);
    }

    public static function registerPanels(): void
    {
        $manager = alfakit();

        if ($manager->hasPanel('api')) {
            return;
        }

        $provider = new self(app());
        $router = app(Router::class);

        foreach ([$provider->panel(Panel::make()), $provider->tenantPanel(Panel::make())] as $panel) {
            $manager->registerPanel($panel);
            $panel->registerRoutes($router);
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
