<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\Assets\Assets;
use AlfaSix\Alfakit\Support\Assets\Css;
use AlfaSix\Alfakit\Support\Assets\Js;

/*
 * Lazy assets (`loadedOnRequest()`), CSS variables and script data (`Assets`).
 */

beforeEach(fn () => Assets::flush());
afterEach(fn () => Assets::flush());

it('keeps assets loaded on request out of the head and exposes their URLs', function (): void {
    Assets::register([
        Css::make('chart', 'https://cdn.test/chart.css')->loadedOnRequest(),
        Js::make('chart', 'https://cdn.test/chart.js')->loadedOnRequest(),
        Css::make('blog', 'https://cdn.test/blog.css'),
    ], package: 'acme/blog');

    $tags = Assets::renderTags('admin');

    expect($tags)
        ->toContain('https://cdn.test/blog.css')
        ->not->toContain('chart.css')
        ->not->toContain('chart.js')
        ->and(Assets::getStyleHref('chart', 'acme/blog'))->toBe('https://cdn.test/chart.css')
        ->and(Assets::getScriptSrc('chart', 'acme/blog'))->toBe('https://cdn.test/chart.js')
        ->and(Assets::getStyleHref('chart'))->toBeNull()
        ->and(Assets::getScriptSrc('missing', 'acme/blog'))->toBeNull()
        ->and(Assets::find('css', 'chart', 'acme/blog')?->isLoadedOnRequest())->toBeTrue();

    // Still published by alfakit:assets.
    expect(array_map(fn ($asset) => $asset->getId(), Assets::all()))->toContain('chart');
});

it('builds local URLs for assets loaded on request', function (): void {
    Assets::register([Css::make('widget', __FILE__)->loadedOnRequest()]);

    expect(Assets::getStyleHref('widget'))->toBe(asset('vendor/alfakit/app/widget.css'));
});

it('renders CSS variables for the panel and drops unsafe values', function (): void {
    Assets::registerCssVariables(['--blog-accent' => '#ff5500', 'blog-banner' => 'url(https://cdn.test/b.jpg)', 'evil' => 'red;}body{display:none'], package: 'acme/blog');
    Assets::registerCssVariables(['tenant-only' => '4px'], panel: 'app');

    $admin = Assets::renderTags('admin', 'abc123');

    expect($admin)
        ->toContain('<style data-alfakit-css-variables nonce="abc123">:root{--blog-accent:#ff5500;--blog-banner:url(https://cdn.test/b.jpg);}</style>')
        ->not->toContain('evil')
        ->not->toContain('tenant-only')
        ->and(Assets::renderTags('app'))->toContain('--tenant-only:4px;')
        ->and(Assets::renderTags(null))->not->toContain('tenant-only');
});

it('rejects CSS variable names that would break the declaration', function (): void {
    Assets::registerCssVariables(['bad name' => 'red']);
})->throws(InvalidArgumentException::class);

it('exposes script data as window.alfakitData, escaped for HTML', function (): void {
    Assets::registerScriptData(['blog' => ['perPage' => 10, 'title' => '</script><script>alert(1)</script>']], package: 'acme/blog');
    Assets::registerScriptData(['adminOnly' => true], panel: ['admin']);

    $admin = Assets::renderTags('admin');

    expect($admin)
        ->toContain('<script data-alfakit-script-data>window.alfakitData=Object.assign(window.alfakitData||{},')
        ->toContain('"perPage":10')
        ->toContain('"adminOnly":true')
        ->not->toContain('</script><script>alert(1)')
        ->and(Assets::getScriptData('app'))->toBe(['blog' => ['perPage' => 10, 'title' => '</script><script>alert(1)</script>']])
        ->and(Assets::renderTags('app'))->not->toContain('adminOnly');

    // Script data comes before registered scripts so they can read it.
    Assets::register([Js::make('blog', 'https://cdn.test/blog.js')]);

    $tags = Assets::renderTags('admin');

    expect(strpos($tags, 'alfakitData'))->toBeLessThan(strpos($tags, 'blog.js'));
});

it('renders nothing extra when nothing is registered', function (): void {
    expect(Assets::renderTags('admin'))->toBe('');
});
