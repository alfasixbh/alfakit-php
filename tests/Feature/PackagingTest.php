<?php

declare(strict_types=1);

/*
 * The split repositories are what Packagist serves: each package root keeps its tests (and
 * their fixture apps with Vite configs) out of the dist archive, so they don't land in
 * vendor/ (W-S4-16), and never leaves out a folder the package needs at runtime.
 */

it('keeps the tests of every split package out of its Composer archive', function (): void {
    $root = dirname(__DIR__, 4);
    preg_match_all('/directory: (packages\/[\w\/-]+)/', (string) file_get_contents($root.'/.github/workflows/split-php.yml'), $matches);

    expect($matches[1])->toContain('packages/alfakit', 'packages/translations')->and(count($matches[1]))->toBeGreaterThan(20);

    foreach ($matches[1] as $directory) {
        $attributes = (string) @file_get_contents("{$root}/{$directory}/.gitattributes");

        expect($attributes)->toContain('/tests export-ignore')
            ->not->toMatch('#^/(src|config|database|resources|routes|stubs|lang|rector)\s+export-ignore#m');
    }
});
