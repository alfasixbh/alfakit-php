<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\SchemaContract;

it('has a major.minor contract version', function (): void {
    expect(SchemaContract::VERSION)->toMatch('/^\d+\.\d+$/');
});

it('extracts the major part of a version', function (): void {
    expect(SchemaContract::major('1.0'))->toBe(1)
        ->and(SchemaContract::major('v2.3.1'))->toBe(2)
        ->and(SchemaContract::major('12'))->toBe(12)
        ->and(SchemaContract::major('dev-main'))->toBeNull()
        ->and(SchemaContract::major(null))->toBeNull()
        ->and(SchemaContract::major(''))->toBeNull();
});

it('treats the same major as compatible and another major as incompatible', function (): void {
    expect(SchemaContract::isCompatible('1.0', '1.7'))->toBeTrue()
        ->and(SchemaContract::isCompatible('1.2', '1.0'))->toBeTrue()
        ->and(SchemaContract::isCompatible('2.0', '1.9'))->toBeFalse()
        ->and(SchemaContract::isCompatible('1.0', '2.0'))->toBeFalse();
});

it('treats unknown versions as compatible', function (): void {
    expect(SchemaContract::isCompatible(null))->toBeTrue()
        ->and(SchemaContract::isCompatible('dev-main', '1.0'))->toBeTrue()
        ->and(SchemaContract::isCompatible('1.0', null))->toBeTrue();
});

it('is compatible with itself', function (): void {
    expect(SchemaContract::isCompatible(SchemaContract::VERSION))->toBeTrue();
});
