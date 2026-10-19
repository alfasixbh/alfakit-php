<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Pivot\CourseResource;
use Illuminate\Routing\Router;

class PanelHomeHiddenPage extends Page
{
    protected static ?string $slug = 'change-password';

    protected static bool $shouldRegisterNavigation = false;
}

class PanelHomeVisiblePage extends Page
{
    protected static ?string $slug = 'reports';
}

function panelHomeUrl(string $id, array $pages): string
{
    $panel = Panel::make()->id($id)->path($id)->middleware(['web'])->pages($pages)->resources([CourseResource::class]);
    alfakit()->registerPanel($panel);

    $router = app(Router::class);
    $panel->registerRoutes($router);
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
    alfakit()->setCurrentPanel($panel);

    return $panel->getUrl();
}

it('sends a panel without a dashboard to its first navigation item, not a hidden page', function (): void {
    // The users resource registers its forced ChangePassword page; starter-kit panels have no dashboard.
    expect(panelHomeUrl('homea', [PanelHomeHiddenPage::class]))->toBe('http://localhost/homea/courses')
        ->and(panelHomeUrl('homeb', [PanelHomeHiddenPage::class, PanelHomeVisiblePage::class]))->toBe('http://localhost/homeb/reports');
});
