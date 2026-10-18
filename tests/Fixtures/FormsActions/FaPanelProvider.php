<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsActions;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Illuminate\Routing\Router;

/**
 * A panel for the forms/actions audit fixes: single-file uploads and wizards in action
 * modals, live bulk-action forms, picker filters, translatable Markdown with a
 * LocaleSwitcher, and live dashboard filters. Registered at runtime by `registerPanel()`.
 */
class FaPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('fa')
            ->path('fa')
            ->login()
            ->middleware(['web'])
            ->locales(['en' => 'English', 'ar' => 'العربية'])
            ->resources([DocumentResource::class])
            ->pages([FaDashboard::class, FaPage::class]);
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('fa')) {
            return $manager->getPanel('fa');
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
