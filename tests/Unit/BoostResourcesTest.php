<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
 * Laravel Boost loads resources/boost/guidelines/core.blade.php and every
 * resources/boost/skills/{name}/SKILL.md from installed packages.
 */

$boost = dirname(__DIR__, 2).'/resources/boost';

it('ships a guideline that renders through Blade', function () use ($boost): void {
    $rendered = Blade::render(file_get_contents("{$boost}/guidelines/core.blade.php"));

    expect($rendered)
        ->toContain('## AlfaKit')
        ->toContain('<code-snippet name="Resource with form, table and actions" lang="php">')
        ->toContain('class PostResource extends Resource')
        ->not->toContain('@verbatim');
});

it('ships skills with valid frontmatter', function (string $directory) use ($boost): void {
    $contents = file_get_contents("{$boost}/skills/{$directory}/SKILL.md");

    expect(preg_match('/\A---\R(.*?)\R---\R/s', $contents, $frontmatter))->toBe(1);

    preg_match('/^name:\s*(.+)$/m', $frontmatter[1], $name);
    preg_match('/^description:\s*(.+)$/m', $frontmatter[1], $description);

    expect(trim($name[1] ?? ''))->toBe($directory)
        ->and(strlen(trim($description[1] ?? '')))->toBeGreaterThan(40)
        ->and($contents)->toContain('## When to use this skill');
})->with(array_map('basename', glob($boost.'/skills/*', GLOB_ONLYDIR) ?: []));

it('only teaches Alfakit namespaces', function () use ($boost): void {
    foreach ([...glob($boost.'/skills/*/SKILL.md'), $boost.'/guidelines/core.blade.php'] as $file) {
        expect(file_get_contents($file))->not->toMatch('/use Filament\\\\/');
    }
});
