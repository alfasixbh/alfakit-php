<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\Deprecations;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    Deprecations::flush();
});

afterEach(function (): void {
    Deprecations::flush();
});

it('logs a deprecation once per process', function (): void {
    Log::spy();

    Deprecations::warn('Table::actions()', 'use recordActions() instead', since: '1.2');
    Deprecations::warn('Table::actions()', 'use recordActions() instead', since: '1.2');
    Deprecations::warn('Table::actions()', 'a different message for the same subject');

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('[Alfakit] Table::actions() is deprecated since Alfakit 1.2: use recordActions() instead', [
            'alfakit' => 'deprecation',
            'subject' => 'Table::actions()',
            'since' => '1.2',
        ]);

    expect(Deprecations::all())->toHaveCount(1)
        ->and(Deprecations::has('Table::actions()'))->toBeTrue()
        ->and(Deprecations::has('Other'))->toBeFalse();
});

it('collects every distinct subject in report order', function (): void {
    Log::spy();

    Deprecations::warn('first', 'use a');
    Deprecations::warn('second', 'use b', since: '0.9');

    expect(Deprecations::all())->toBe([
        ['subject' => 'first', 'message' => 'use a', 'since' => null],
        ['subject' => 'second', 'message' => 'use b', 'since' => '0.9'],
    ]);

    expect(Deprecations::format('first', 'use a'))->toBe('[Alfakit] first is deprecated: use a');
});

it('exposes the list to the debug prop only in debug mode', function (): void {
    Log::spy();
    Deprecations::warn('legacy', 'use modern');

    config(['app.debug' => false]);
    expect(Deprecations::toDebug())->toBeNull();

    config(['app.debug' => true]);
    expect(Deprecations::toDebug())->toBe([['subject' => 'legacy', 'message' => 'use modern', 'since' => null]]);
});

it('can be flushed', function (): void {
    Log::spy();
    Deprecations::warn('legacy', 'use modern');
    Deprecations::flush();

    expect(Deprecations::all())->toBe([]);

    // A flushed subject is reported again.
    Deprecations::warn('legacy', 'use modern');
    Log::shouldHaveReceived('warning')->twice();
});
