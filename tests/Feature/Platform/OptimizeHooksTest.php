<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;

it('registers alfakit:optimize and alfakit:optimize-clear with Laravel\'s optimize hooks', function (): void {
    expect(ServiceProvider::$optimizeCommands)->toHaveKey('alfakit', 'alfakit:optimize')
        ->and(ServiceProvider::$optimizeCommands['alfakit'])->toBe('alfakit:optimize')
        ->and(ServiceProvider::$optimizeClearCommands['alfakit'])->toBe('alfakit:optimize-clear');
});
