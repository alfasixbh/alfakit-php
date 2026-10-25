<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\CreatePost;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\EditPost;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\ListPosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class RouteOrderPostResource extends PostResource
{
    protected static ?string $slug = 'route-order-posts';

    public static function getPages(): array
    {
        // A custom page appended after the record pages, as `make:alfakit-page --resource` suggests.
        return [
            'index' => ListPosts::route('/'),
            'view' => EditPost::route('/{record}'),
            'edit' => EditPost::route('/{record}/edit'),
            'reports' => CreatePost::route('/reports'),
        ];
    }
}

it('serves a fixed page path listed after the record pages', function (): void {
    $panel = alfakit()->getDefaultPanel();

    Route::name('route-order.')->group(fn () => RouteOrderPostResource::registerRoutes($panel));
    Route::getRoutes()->refreshNameLookups();

    $route = Route::getRoutes()->match(Request::create('/'.RouteOrderPostResource::getRoutePrefix().'/reports'));

    expect($route->getName())->toBe('route-order.'.RouteOrderPostResource::getRouteBaseName().'.reports')
        ->and(Route::getRoutes()->match(Request::create('/'.RouteOrderPostResource::getRoutePrefix().'/5'))->getName())
        ->toBe('route-order.'.RouteOrderPostResource::getRouteBaseName().'.view');
});
