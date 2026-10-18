<?php

declare(strict_types=1);

/*
 * Agents copy what the guideline, skills and docs show. Every `use AlfaSix\Alfakit\…`
 * import in them must point at a class, interface, trait or enum that exists, so the
 * code they learn compiles against the current API.
 */

$package = dirname(__DIR__, 2);
$docs = dirname($package, 2).'/docs/content';

/**
 * @return array<int, string> Fully qualified names imported in the text (group imports expanded).
 */
function alfakitImports(string $text): array
{
    $names = [];

    preg_match_all('/^\s*use\s+(AlfaSix\\\\Alfakit\\\\[^;{]*?)(?:\\\\\{([^}]*)\})?\s*;/m', $text, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $base = rtrim(trim($match[1]), '\\');

        if (! isset($match[2]) || $match[2] === '') {
            $names[] = preg_replace('/\s+as\s+\w+$/i', '', $base);

            continue;
        }

        foreach (explode(',', $match[2]) as $member) {
            $member = trim(preg_replace('/\s+as\s+\w+$/i', '', trim($member)) ?? '');

            if ($member !== '') {
                $names[] = $base.'\\'.$member;
            }
        }
    }

    return array_values(array_unique(array_filter($names, static fn (?string $name): bool => is_string($name) && ! str_starts_with($name, 'function ') && ! str_starts_with($name, 'const '))));
}

$files = array_filter([
    ...glob($package.'/resources/boost/skills/*/SKILL.md') ?: [],
    $package.'/resources/boost/guidelines/core.blade.php',
    ...(is_dir($docs) ? (function (string $root): array {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'md') {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    })($docs) : []),
]);

it('only imports AlfaKit classes that exist', function (string $file): void {
    $missing = array_values(array_filter(
        alfakitImports((string) file_get_contents($file)),
        static fn (string $name): bool => ! class_exists($name) && ! interface_exists($name) && ! trait_exists($name) && ! enum_exists($name),
    ));

    expect($missing)->toBe([], 'Unknown AlfaKit imports in '.basename(dirname($file)).'/'.basename($file).': '.implode(', ', $missing));
})->with(array_combine(
    array_map(static fn (string $file): string => str_replace(dirname($package, 2).'/', '', $file), $files),
    array_map(static fn (string $file): array => [$file], $files),
));

it('expands grouped imports', function (): void {
    expect(alfakitImports("use AlfaSix\\Alfakit\\Actions\\{DeleteAction, EditAction as Edit};\nuse AlfaSix\\Alfakit\\Panel;"))
        ->toBe(['AlfaSix\\Alfakit\\Actions\\DeleteAction', 'AlfaSix\\Alfakit\\Actions\\EditAction', 'AlfaSix\\Alfakit\\Panel']);
});
