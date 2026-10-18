<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use Closure;
use Illuminate\Routing\Router;

/**
 * The panel of the page-parity tests (default actions, content, form helpers, wizards,
 * list tabs), registered at runtime by `registerPanel()`.
 */
class ParityPanelProvider extends PanelProvider
{
    /** @var (Closure(Panel): Panel)|null */
    public static ?Closure $configureUsing = null;

    public function panel(Panel $panel): Panel
    {
        $panel = $panel
            ->id('parity')
            ->path('parity')
            ->login()
            ->middleware(['web'])
            ->resources([ParityPostResource::class, ParityWizardResource::class]);

        return static::$configureUsing !== null ? (static::$configureUsing)($panel) : $panel;
    }

    public static function registerPanel(): Panel
    {
        $manager = alfakit();

        if ($manager->hasPanel('parity')) {
            return $manager->getPanel('parity');
        }

        $panel = (new self(app()))->panel(Panel::make('parity'));
        $manager->registerPanel($panel);

        $router = app(Router::class);
        $panel->registerRoutes($router);
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        return $panel;
    }
}
