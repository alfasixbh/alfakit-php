<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\NumberFormat;
use Illuminate\Support\Number;

/*
 * Server-side file sizes match the renderers' `fileManagerSize()`: 1024-based units,
 * bytes spelled out and pluralised, in the panel's locale.
 */

it('formats file sizes like the renderers', function (): void {
    expect(NumberFormat::fileSize(0, locale: 'en'))->toBe('0 bytes')
        ->and(NumberFormat::fileSize(1, locale: 'en'))->toBe('1 byte')
        ->and(NumberFormat::fileSize(11, locale: 'en'))->toBe('11 bytes')
        ->and(NumberFormat::fileSize(1536, locale: 'en'))->toBe('1.5 kB')
        ->and(NumberFormat::fileSize(1024 * 1024 * 12.25, 2, 'en'))->toBe('12.25 MB')
        ->and(NumberFormat::fileSize(2048, 0, 'en'))->toBe('2 kB');
});

it('localises the number and the unit', function (): void {
    expect(NumberFormat::fileSize(11, locale: 'fr'))->toMatch('/^11\s+octets$/u')
        ->and(NumberFormat::fileSize(1536, locale: 'fr'))->toMatch('/^1,5\s+ko$/u')
        ->and(NumberFormat::fileSize(11, locale: 'ar'))->toContain('بايت');
});

it('uses the panel locale by default', function (): void {
    $previous = Number::defaultLocale();
    Number::useLocale('de');

    try {
        expect(NumberFormat::fileSize(1536))->toMatch('/^1,5\s+kB$/u');
    } finally {
        Number::useLocale($previous);
    }
});
