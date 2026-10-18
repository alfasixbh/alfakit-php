<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\ColorContrast;
use AlfaSix\Alfakit\Support\Colors\Color;
use AlfaSix\Alfakit\Support\Colors\CssColor;
use AlfaSix\Alfakit\Theming\Theme;
use AlfaSix\Alfakit\Theming\Themes;
use Illuminate\Support\Str;

afterEach(fn () => Themes::flush());

it('ships every Tailwind v4 palette as 11 OKLCH shades', function (): void {
    expect(Color::all())->toHaveCount(26);

    foreach (Color::all() as $name => $palette) {
        expect(array_keys($palette))->toBe(Color::SHADES, $name);

        foreach ($palette as $shade) {
            expect($shade)->toStartWith('oklch(');
        }
    }

    expect(Color::convertToHex(Color::Indigo[500]))->toBe('#615fff')
        ->and(Color::convertToHex(Color::Blue[600]))->toBe('#155dfc');
});

it('generates an OKLCH palette from hex, rgb and oklch colours', function (): void {
    $palette = Color::hex('#6366f1');

    expect(array_keys($palette))->toBe(Color::SHADES)
        ->and(Color::rgb('rgb(99, 102, 241)'))->toBe($palette)
        ->and(Color::generatePalette(Color::convertToOklch('#6366f1')))->toBe($palette)
        ->and(Color::parseOklch($palette[50])[0])->toBeGreaterThan(Color::parseOklch($palette[950])[0]);

    // The hue is kept for every shade.
    $hue = Color::parseOklch('#6366f1')[2];

    foreach ($palette as $shade) {
        expect(Color::parseOklch($shade)[2])->toEqualWithDelta($hue, 0.01);
    }

    // Greys stay neutral.
    expect(Color::parseOklch(Color::hex('#808080')[500])[1])->toBeLessThan(0.001);
});

it('rejects colours it cannot read', function (): void {
    Color::hex('var(--brand)');
})->throws(InvalidArgumentException::class);

it('measures WCAG contrast and finds accessible shades', function (): void {
    expect(Color::calculateContrastRatio('#000', '#fff'))->toEqualWithDelta(21.0, 0.01)
        ->and(Color::isTextContrastRatioAccessible('#ffffff', Color::Blue[700]))->toBeTrue()
        ->and(Color::isTextContrastRatioAccessible('#ffffff', Color::Amber[400]))->toBeFalse()
        ->and(Color::isNonTextContrastRatioAccessible('#ffffff', Color::Amber[600]))->toBeTrue()
        ->and(Color::findShade(Color::Amber, '#ffffff', Color::WCAG_AA_TEXT))->toBe(700)
        ->and(Color::findShade(Color::Amber, '#000000', Color::WCAG_AA_TEXT, shouldStartFromDarkest: true))->toBe(600)
        ->and(Color::findShade(Color::Amber, '#ffffff', Color::WCAG_AAA_TEXT, maxShade: 600))->toBeNull()
        ->and(Color::isLight(Color::Amber[300]))->toBeTrue()
        ->and(Color::isLight(Color::Slate[900]))->toBeFalse();
});

it('picks the 600 shade when white text reads on it, else 500', function (): void {
    expect(Color::getBaseShade(Color::Indigo))->toBe(600)
        ->and(Color::getBaseShade(Color::Amber))->toBe(500)
        ->and(Color::getBaseShade(Color::Yellow))->toBe(500);
});

it('emits palette shades and the base colour on the panel', function (): void {
    $panel = Panel::make('palettes')->colors(['primary' => Color::Indigo, 'warning' => Color::Amber, 'danger' => '#dc2626']);

    $style = $panel->getHtmlThemeStyle();

    expect($panel->getThemeColors())->toBe(['primary' => Color::Indigo[600], 'warning' => Color::Amber[500], 'danger' => '#dc2626'])
        ->and($style)
        ->toContain('--alfakit-primary: '.Color::Indigo[600])
        ->toContain('--alfakit-primary-foreground: '.ColorContrast::LIGHT)
        ->toContain('--alfakit-primary-50: '.Color::Indigo[50])
        ->toContain('--alfakit-primary-950: '.Color::Indigo[950])
        ->toContain('--alfakit-warning: '.Color::Amber[500])
        ->toContain('--alfakit-warning-foreground: '.ColorContrast::DARK)
        ->toContain('--alfakit-danger: #dc2626')
        ->not->toContain('--alfakit-danger-500');

    // Shared with the frontend so Inertia visits between panels keep them in sync.
    expect($panel->getThemeColorVariables())
        ->toHaveKey('primary-500', Color::Indigo[500])
        ->toHaveKey('primary-foreground')
        ->toHaveKey('danger', '#dc2626');
});

it('gives custom palette names a colour class and theme palettes a lower priority', function (): void {
    Themes::register(Theme::make('acme')->extends('alfakit')->colors(['primary' => Color::Emerald, 'info' => Color::Sky]));

    $panel = Panel::make('custom-palettes')->theme('acme')->colors(['brand' => Color::hex('#ff5500'), 'info' => '#0ea5e9']);

    expect($panel->getThemeColors()['primary'])->toBe(Color::Emerald[Color::getBaseShade(Color::Emerald)])
        ->and($panel->getThemeColors()['info'])->toBe('#0ea5e9')
        ->and($panel->getThemeColorPalettes())->toHaveKeys(['primary', 'brand'])->not->toHaveKey('info')
        ->and($panel->getCustomColorCss())->toContain('.alfakit-color-brand{--alfakit-c:var(--alfakit-brand);')
        ->and($panel->getHtmlThemeStyle())->toContain('--alfakit-brand-100: ');
});

it('sanitises CSS colours for inline styles', function (string $color, ?string $expected): void {
    expect(CssColor::sanitize($color))->toBe($expected)
        ->and(Str::sanitizeCssColor($color))->toBe($expected)
        ->and(str($color)->sanitizeCssColor()->toString())->toBe($expected ?? '');
})->with([
    'hex' => ['#ff0000', '#ff0000'],
    'short hex with alpha' => ['#f008', '#f008'],
    'rgb' => ['rgb(255, 0, 0)', 'rgb(255, 0, 0)'],
    'oklch with alpha' => ['oklch(0.6 0.2 30 / 50%)', 'oklch(0.6 0.2 30 / 50%)'],
    'hsl with deg' => ['hsl(30deg 50% 50%)', 'hsl(30deg 50% 50%)'],
    'keyword' => ['rebeccapurple', 'rebeccapurple'],
    'url' => ['url(https://evil.test/x.png)', null],
    'var' => ['var(--x)', null],
    'injected declaration' => ['red; background: url(x)', null],
    'nested function' => ['rgb(calc(1) 0 0)', null],
    'expression' => ['expression(alert(1))', null],
]);

it('adds fluent sanitising macros to Stringable', function (): void {
    expect(str('<p onclick="x()">Hi</p>')->sanitizeHtml()->toString())->toBe('<p>Hi</p>')
        ->and(str('javascript:alert(1)')->sanitizeUrl()->toString())->toBe('')
        ->and(str('https://alfakit.dev')->sanitizeUrl()->toString())->toBe('https://alfakit.dev');
});
