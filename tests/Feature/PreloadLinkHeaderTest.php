<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Http\Middleware\LimitPreloadLinkHeader;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;

/**
 * Fills `Vite::preloadedAssets()` as `@vite` does for a page that preloads `$count` chunks.
 */
function preloadChunks(int $count): void
{
    $vite = new Vite;
    $assets = [];

    for ($i = 1; $i <= $count; $i++) {
        $assets["https://example.com/build/assets/chunk-{$i}.js"] = ['rel="modulepreload"', 'as="script"'];
    }

    (new ReflectionProperty(Vite::class, 'preloadedAssets'))->setValue($vite, $assets);
    app()->instance(Vite::class, $vite);
    Illuminate\Support\Facades\Vite::clearResolvedInstances();
}

/**
 * The response of a page behind LimitPreloadLinkHeader and Laravel's own middleware, in the
 * order a panel route runs them (AlfaKit's first, the `web` group inside it).
 */
function pageThroughLinkMiddleware(?string $laravelLimit = null, ?Closure $page = null): Response
{
    $inner = fn (Request $request): Response => (new AddLinkHeadersForPreloadedAssets)->handle(
        $request,
        $page ?? fn (): Response => new Response('page'),
        $laravelLimit,
    );

    /** @var Response */
    return (new LimitPreloadLinkHeader)->handle(Request::create('/admin/posts'), $inner);
}

it('keeps only the first preload entries of the Link header on AlfaKit pages', function (): void {
    preloadChunks(120);

    $header = (string) pageThroughLinkMiddleware()->headers->get('Link');

    expect(substr_count($header, 'rel="modulepreload"'))->toBe(20)
        ->and($header)->toStartWith('<https://example.com/build/assets/chunk-1.js>; rel="modulepreload"; as="script", ')
        ->and($header)->toContain('chunk-20.js')
        ->and($header)->not->toContain('chunk-21.js')
        ->and(strlen($header))->toBeLessThan(2048);
});

it('follows alfakit.inertia.preload_link_limit, and null keeps every entry', function (): void {
    preloadChunks(50);

    config(['alfakit.inertia.preload_link_limit' => 5]);
    expect(substr_count((string) pageThroughLinkMiddleware()->headers->get('Link'), 'modulepreload'))->toBe(5);

    config(['alfakit.inertia.preload_link_limit' => null]);
    expect(substr_count((string) pageThroughLinkMiddleware()->headers->get('Link'), 'modulepreload'))->toBe(50);

    config(['alfakit.inertia.preload_link_limit' => 0]);
    expect(pageThroughLinkMiddleware()->headers->has('Link'))->toBeFalse();
});

it('leaves pages with few preloads, other Link values and limits the app set itself alone', function (): void {
    preloadChunks(8);
    expect(substr_count((string) pageThroughLinkMiddleware()->headers->get('Link'), 'modulepreload'))->toBe(8);

    preloadChunks(60);

    // The app limited Laravel's middleware itself (`AddLinkHeadersForPreloadedAssets::using(30)`).
    expect(substr_count((string) pageThroughLinkMiddleware('30')->headers->get('Link'), 'modulepreload'))->toBe(30);

    // A Link value the page set itself stays as it is.
    $response = pageThroughLinkMiddleware(page: function (): Response {
        $response = new Response('page');
        $response->headers->set('Link', '<https://fonts.example.com>; rel="preconnect"');

        return $response;
    });

    expect($response->headers->all('link'))->toHaveCount(2)
        ->and($response->headers->all('link')[0])->toBe('<https://fonts.example.com>; rel="preconnect"')
        ->and(substr_count((string) $response->headers->all('link')[1], 'modulepreload'))->toBe(20);
});

it('runs before the web group on panel routes, so it sees the header Laravel adds', function (): void {
    /** @var Router $router */
    $router = app('router');
    $router->pushMiddlewareToGroup('web', AddLinkHeadersForPreloadedAssets::class);

    $route = $router->getRoutes()->match(Request::create('/admin/posts'));
    $middleware = array_map(
        static fn (mixed $item): string => is_string($item) ? $item : 'closure',
        $router->gatherRouteMiddleware($route),
    );

    $alfakit = array_search(LimitPreloadLinkHeader::class, $middleware, true);
    $laravel = array_search(AddLinkHeadersForPreloadedAssets::class, $middleware, true);

    expect($alfakit)->toBe(0)
        ->and($laravel)->toBeInt()
        ->and($alfakit)->toBeLessThan($laravel);
});
