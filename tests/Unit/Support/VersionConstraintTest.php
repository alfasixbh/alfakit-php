<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\VersionConstraint;

it('parses versions', function (): void {
    expect(VersionConstraint::parse('1.2.3'))->toBe([1, 2, 3])
        ->and(VersionConstraint::parse('v1.2'))->toBe([1, 2, 0])
        ->and(VersionConstraint::parse('2'))->toBe([2, 0, 0])
        ->and(VersionConstraint::parse('1.2.3.0'))->toBe([1, 2, 3])
        ->and(VersionConstraint::parse('1.0.0-beta1'))->toBe([1, 0, 0])
        ->and(VersionConstraint::parse('dev-main'))->toBeNull()
        ->and(VersionConstraint::parse('1.x-dev'))->toBeNull()
        ->and(VersionConstraint::parse(''))->toBeNull();
});

it('matches caret constraints', function (string $version, string $constraint, bool $expected): void {
    expect(VersionConstraint::satisfies($version, $constraint))->toBe($expected);
})->with([
    ['1.4.0', '^1.0', true],
    ['1.0.0', '^1.0', true],
    ['2.0.0', '^1.0', false],
    ['0.9.9', '^1.0', false],
    ['0.2.5', '^0.2', true],
    ['0.3.0', '^0.2', false],
    ['0.0.4', '^0.0.3', false],
    ['0.0.3', '^0.0.3', true],
    ['v1.4.2', '^1.2.0', true],
]);

it('matches tilde, wildcard, exact and comparison constraints', function (string $version, string $constraint, bool $expected): void {
    expect(VersionConstraint::satisfies($version, $constraint))->toBe($expected);
})->with([
    ['1.5.0', '~1.2', true],
    ['2.0.0', '~1.2', false],
    ['1.2.9', '~1.2.3', true],
    ['1.3.0', '~1.2.3', false],
    ['1.9.0', '1.*', true],
    ['2.0.0', '1.*', false],
    ['1.2.7', '1.2.x', true],
    ['1.3.0', '1.2.*', false],
    ['1.2.3', '1.2.3', true],
    ['1.2.4', '1.2.3', false],
    ['1.2.3', '=1.2.3', true],
    ['1.5.0', '>=1.0 <2.0', true],
    ['2.0.0', '>=1.0 <2.0', false],
    ['1.5.0', '>=1.0,<2.0', true],
    ['1.5.0', '!=1.5.0', false],
    ['1.5.0', '>1.5.0', false],
    ['1.5.0', '<=1.5.0', true],
    ['3.0.0', '*', true],
    ['1.5.0', '^1.0@dev', true],
]);

it('matches alternatives joined with ||', function (): void {
    expect(VersionConstraint::satisfies('2.1.0', '^1.0 || ^2.0'))->toBeTrue()
        ->and(VersionConstraint::satisfies('3.0.0', '^1.0 || ^2.0'))->toBeFalse()
        ->and(VersionConstraint::satisfies('1.0.0', '^1.0|^2.0'))->toBeTrue();
});

it('cannot decide for development versions or unparsable constraints', function (): void {
    expect(VersionConstraint::satisfies('dev-main', '^1.0'))->toBeNull()
        ->and(VersionConstraint::satisfies('1.0.0', 'dev-main'))->toBeNull()
        ->and(VersionConstraint::satisfies('1.0.0', '^banana'))->toBeNull();
});
