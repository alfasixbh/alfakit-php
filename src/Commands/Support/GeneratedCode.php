<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

/**
 * Clean-ups for generated PHP files, so they pass Pint's Laravel preset in a fresh app.
 */
final class GeneratedCode
{
    /**
     * Sort, de-duplicate and drop unused top-level `use` imports (classes only), the way
     * Pint's `ordered_imports` and `no_unused_imports` rules expect them.
     */
    public static function tidyImports(string $code): string
    {
        if (preg_match('/^(use [^;\n]+;\n(?:\n*use [^;\n]+;\n)*)/m', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $code;
        }

        [$block, $offset] = $match[1];
        $body = substr($code, $offset + strlen($block));
        $imports = [];
        $other = [];

        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^use ([^;]+);$/', trim($line), $use) !== 1) {
                continue;
            }

            if (str_starts_with($use[1], 'function ') || str_starts_with($use[1], 'const ')) {
                $other[] = "use {$use[1]};\n";

                continue;
            }

            $class = ltrim($use[1], '\\');
            $alias = str_contains($class, ' as ') ? trim(substr($class, strrpos($class, ' as ') + 4)) : substr($class, (int) strrpos('\\'.$class, '\\'));

            if (preg_match('/(?<![\\\\\w$])'.preg_quote($alias, '/').'\b/', $body) === 1) {
                $imports[strtolower($class)] = $class;
            }
        }

        $imports = array_values($imports);
        usort($imports, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', $a), str_replace('\\', ' ', $b)));

        $tidy = implode('', array_map(static fn (string $class): string => "use {$class};\n", $imports)).($other === [] ? '' : "\n".implode('', array_unique($other)));

        return substr($code, 0, $offset).$tidy.$body;
    }
}
