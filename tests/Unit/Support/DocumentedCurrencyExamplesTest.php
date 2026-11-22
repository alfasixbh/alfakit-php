<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\Currency;

// The values the localization docs state next to each call (currencies-and-numbers.md).
it('gives the results the docs state for the Currency helpers', function (): void {
    app()->setLocale('en');

    expect(Currency::decimals('BHD'))->toBe(3)
        ->and(Currency::decimals('JPY'))->toBe(0)
        ->and(Currency::decimals('USD'))->toBe(2)
        ->and(Currency::name('JPY', 'de'))->toBe('Japanischer Yen')
        ->and(Currency::symbol('EUR', 'de'))->toBe('€')
        ->and(Currency::toMinorUnits('19.99', 'USD'))->toBe(1999)
        ->and(Currency::fromMinorUnits(12345, 'BHD'))->toBe('12.345')
        ->and(Currency::format(12500, 'BHD', inMinorUnits: true))->toContain('12.500')->toContain('BHD');
});
