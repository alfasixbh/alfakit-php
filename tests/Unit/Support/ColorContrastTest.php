<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\ColorContrast;

it('picks dark text for pale colours and light text for deep ones', function (string $color, ?string $expected): void {
    expect(ColorContrast::foreground($color))->toBe($expected);
})->with([
    'pale yellow hex' => ['#FDE68A', ColorContrast::DARK],
    'white short hex' => ['#fff', ColorContrast::DARK],
    'alfa navy' => ['#073B78', ColorContrast::LIGHT],
    'fox orange' => ['#F47A22', ColorContrast::DARK],
    'blue 600' => ['#2563eb', ColorContrast::LIGHT],
    'rgb pale' => ['rgb(240, 240, 255)', ColorContrast::DARK],
    'rgb deep' => ['rgba(20, 20, 60, 1)', ColorContrast::LIGHT],
    'oklch pale' => ['oklch(0.95 0.05 90)', ColorContrast::DARK],
    'oklch deep' => ['oklch(0.35 0.12 256)', ColorContrast::LIGHT],
    'unparseable' => ['var(--brand)', null],
    'named' => ['rebeccapurple', null],
]);

it('emits a foreground variable for each status colour it can read', function (): void {
    $style = Panel::make('contrast')->colors(['primary' => '#FDE68A', 'danger' => '#7f1d1d', 'secondary' => '#fef3c7', 'info' => 'var(--x)'])->getHtmlThemeStyle();

    expect($style)
        ->toContain('--alfakit-primary: #FDE68A; --alfakit-primary-foreground: '.ColorContrast::DARK)
        ->toContain('--alfakit-danger-foreground: '.ColorContrast::LIGHT)
        ->not->toContain('--alfakit-secondary-foreground')
        ->not->toContain('--alfakit-info-foreground');
});

it('reads the foreground variables in the shared and renderer CSS', function (): void {
    $root = dirname(__DIR__, 5);

    foreach (['packages/react/src/styles.css', 'packages/vue/src/styles.css', 'packages/svelte/src/styles.css'] as $file) {
        $css = (string) file_get_contents($root.'/'.$file);

        foreach (['primary' => 'primary', 'destructive' => 'danger', 'success' => 'success', 'warning' => 'warning', 'info' => 'info'] as $var => $color) {
            expect($css)->toContain("--{$var}-foreground: var(--alfakit-{$color}-foreground,");
        }
    }

    expect((string) file_get_contents($root.'/packages/core/themes.css'))->toContain('--primary-foreground: var(--alfakit-primary-foreground,');
});
