<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource;
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

class PanelHomeGroupedPage extends Page
{
    protected static ?string $slug = 'site-design';

    public static function getNavigationGroup(): ?string
    {
        return 'Content';
    }
}

class PanelHomeSortedPage extends Page
{
    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = -5;
}

function panelHomeUrl(string $id, array $pages, array $resources = [CourseResource::class]): string
{
    $panel = Panel::make()->id($id)->path($id)->middleware(['web'])->pages($pages)->resources($resources);
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

it('never sends a panel without a dashboard to a nested resource', function (): void {
    // ArticleCommentResource is nested under ArticleResource: its list needs a {post}.
    expect(panelHomeUrl('homec', [PanelHomeHiddenPage::class], [ArticleCommentResource::class, ArticleResource::class]))->toBe('http://localhost/homec/articles')
        ->and(ArticleCommentResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ArticleResource::shouldRegisterNavigation())->toBeTrue();
});

it('sends a panel without a dashboard to the first item in sidebar order, not a grouped plugin page', function (): void {
    // A plugin page in a later group (the CMS "Site design") stays behind the app's ungrouped resources.
    expect(panelHomeUrl('homed', [PanelHomeGroupedPage::class]))->toBe('http://localhost/homed/courses')
        // Ungrouped items are ordered by navigationSort, pages and resources together.
        ->and(panelHomeUrl('homee', [PanelHomeGroupedPage::class, PanelHomeSortedPage::class]))->toBe('http://localhost/homee/overview');
});
