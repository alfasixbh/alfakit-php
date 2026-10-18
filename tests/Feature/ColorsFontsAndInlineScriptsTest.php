<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Assets\Assets;
use AlfaSix\Alfakit\Support\Assets\Js;
use AlfaSix\Alfakit\Support\Colors\Color;
use AlfaSix\Alfakit\Support\Colors\Colors;
use AlfaSix\Alfakit\Theming\FontProvider;
use Illuminate\Support\HtmlString;

beforeEach(function (): void {
    Colors::flush();
    Assets::flush();
});

afterEach(function (): void {
    Colors::flush();
    Assets::flush();
});

// ---------------------------------------------------------------------- colors()

it('accepts a closure in colors() and evaluates it once per request', function (): void {
    $calls = 0;
    $panel = Panel::make('closure-colors')->colors(function () use (&$calls): array {
        $calls++;

        return ['primary' => '#ff0000', 'brand' => '#00ff00'];
    });

    expect($panel->getThemeColorVariables())->toMatchArray(['primary' => '#ff0000', 'brand' => '#00ff00'])
        ->and($panel->getHtmlThemeStyle())->toContain('--alfakit-primary: #ff0000');

    $panel->getThemeColors();
    expect($calls)->toBe(1);

    // A new request re-evaluates it.
    $panel->flushThemeCache()->getThemeColors();
    expect($calls)->toBe(2);
});

it('injects the authenticated user into colors() closures', function (): void {
    $this->actingAs(admin());

    $panel = Panel::make('user-colors')->colors(fn ($user): array => ['primary' => $user !== null ? '#111111' : '#222222']);

    expect($panel->getThemeColors()['primary'])->toBe('#111111');
});

it('merges array and closure colours, later calls winning', function (): void {
    $panel = Panel::make('merged-colors')
        ->colors(['primary' => '#000001', 'danger' => '#000002'])
        ->colors(fn (): array => ['primary' => Color::Indigo])
        ->colors(fn (): null => null);

    $colors = $panel->getColors();

    expect($colors['danger'])->toBe('#000002')
        ->and($colors['primary'])->toBe(Color::Indigo)
        ->and($panel->getThemeColorVariables())->toHaveKey('primary-500');
});

it('registers colours for every panel with Colors::register()', function (): void {
    Colors::register(['brand' => '#123456', 'primary' => '#654321']);
    Colors::register(fn (): array => ['accent' => '#abcdef']);

    $panel = Panel::make('global-colors')->colors(['primary' => '#0000ff']);

    expect($panel->getThemeColors())->toMatchArray(['brand' => '#123456', 'accent' => '#abcdef', 'primary' => '#0000ff'])
        ->and($panel->getCustomColorCss())->toContain('.alfakit-color-brand')
        ->and(Panel::make('other-panel')->getThemeColors()['primary'])->toBe('#654321');
});

it('lets registered colours override the theme', function (): void {
    $themePrimary = Panel::make('theme-only')->getThemeColors()['primary'] ?? null;
    Colors::register(['primary' => '#010203']);

    expect(Panel::make('registered')->getThemeColors()['primary'])->toBe('#010203')
        ->and($themePrimary)->not->toBe('#010203');
});

// ---------------------------------------------------------------------- font(url:)

it('loads a font from your own stylesheet with url:', function (): void {
    $panel = Panel::make('local-font')->font('Inter', url: '/fonts/inter.css');

    expect($panel->getFontProvider())->toBe(FontProvider::Local)
        ->and($panel->getFontUrl())->toBe('/fonts/inter.css')
        ->and($panel->getFontUrls())->toBe(['/fonts/inter.css'])
        ->and($panel->getFontCssValue())->toBe("'Inter'");
});

it('accepts a closure url and a heading font url', function (): void {
    $panel = Panel::make('local-heading')
        ->font('Inter', FontProvider::Local, url: fn (): string => 'https://cdn.example.com/inter.css')
        ->headingFont('Sora', url: '/fonts/sora.css');

    expect($panel->getFontUrls())->toBe(['https://cdn.example.com/inter.css', '/fonts/sora.css']);
});

it('ignores unsafe or missing local font urls', function (): void {
    expect(Panel::make('bad-font')->font('Inter', url: 'javascript:alert(1)')->getFontUrl())->toBeNull()
        ->and(Panel::make('no-url')->font('Inter', FontProvider::Local)->getFontUrl())->toBeNull()
        ->and(Panel::make('bunny')->font('Inter')->getFontUrl())->toContain('fonts.bunny.net');
});

// ---------------------------------------------------------------------- Js::html()

it('renders inline scripts with the CSP nonce', function (): void {
    Assets::register([
        Js::make('analytics')->html('<script>window.analytics = true</script>'),
        Js::make('bare')->html('window.bare = 1'),
        Js::make('htmlable')->html(new HtmlString('<script nonce="own">window.own = 1</script>')),
    ]);

    $html = Assets::renderTags('admin', 'n0nce');

    expect($html)->toContain('<script nonce="n0nce">window.analytics = true</script>')
        ->toContain('<script nonce="n0nce" data-alfakit-asset="app/bare">window.bare = 1</script>')
        ->toContain('<script nonce="own">window.own = 1</script>')
        ->not->toContain('src=""');
});

it('renders inline scripts without a nonce when there is none, and skips them when publishing', function (): void {
    $script = Js::make('lazy')->html(fn (): string => '<script>1</script>');

    expect($script->getInlineHtml())->toBe('<script>1</script>')
        ->and($script->isInline())->toBeTrue()
        ->and($script->isRemote())->toBeTrue()
        ->and(Js::make('file', __DIR__.'/x.js')->isInline())->toBeFalse();
});

it('renders inline scripts in the root view', function (): void {
    Assets::register([Js::make('root-inline')->html('<script>window.rootInline = 1</script>')]);

    $this->actingAs(admin());

    expect($this->get('/admin')->getContent())->toContain('window.rootInline = 1');
});
