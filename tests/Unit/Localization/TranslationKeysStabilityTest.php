<?php

declare(strict_types=1);

/*
 * docs/content/advanced/stability.md: a renamed key in `alfakit::ui` (and the other
 * `alfakit::*` groups) keeps the old key as an alias for the rest of the major. The fixture
 * lists every English key of the 0.x line; a key may be added, never dropped. Renaming one
 * means keeping the old key too (same text, or the new text) until the next major.
 */

it('keeps every translation key of this major', function (): void {
    $current = [];
    $walk = function (array $lines, string $prefix) use (&$walk, &$current): void {
        foreach ($lines as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            is_array($value) ? $walk($value, $path) : $current[$path] = true;
        }
    };

    foreach (glob(dirname(__DIR__, 3).'/resources/lang/en/*.php') ?: [] as $file) {
        $walk(require $file, basename($file, '.php'));
    }

    $snapshot = array_filter(explode("\n", (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/Localization/translation-keys-0.1.txt')));
    $missing = array_values(array_filter($snapshot, static fn (string $key): bool => ! isset($current[$key])));

    expect($missing)->toBe([], "Translation keys removed (keep them as aliases until the next major):\n".implode("\n", $missing));
});
