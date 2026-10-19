<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use Illuminate\Support\Str;

/**
 * Small, idempotent source edits to a generated panel provider (`return $panel->…;`):
 * adding imports and chained calls, and replacing a call's arguments. Every method returns
 * the new contents, the same contents when nothing had to change, or null when the file
 * doesn't have the expected shape (the caller then tells the user what to add).
 */
final class PanelProviderEditor
{
    public static function path(string $panelId): string
    {
        return app_path('Providers/Alfakit/'.Str::studly($panelId).'PanelProvider.php');
    }

    public static function hasCall(string $contents, string $method): bool
    {
        return preg_match('/->'.preg_quote($method, '/').'\s*\(/', $contents) === 1;
    }

    /**
     * Add `use Fqcn;` to the file's top-level imports, keeping them sorted.
     */
    public static function addImport(string $contents, string $class): string
    {
        $class = ltrim($class, '\\');

        if (preg_match('/^use '.preg_quote($class, '/').';$/m', $contents) === 1) {
            return $contents;
        }

        [$head, $tail] = self::splitAtClass($contents);

        if (preg_match_all('/^use [^;]+;$/m', $head, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($matches[0]);
            $end = (int) $last[1] + strlen($last[0]);

            return self::sortImports(substr($head, 0, $end)."\nuse {$class};".substr($head, $end).$tail);
        }

        if (preg_match('/^namespace [^;]+;\n/m', $head, $namespace, PREG_OFFSET_CAPTURE) === 1) {
            $position = (int) $namespace[0][1] + strlen($namespace[0][0]);

            return substr($head, 0, $position)."\nuse {$class};\n".substr($head, $position).$tail;
        }

        return $contents;
    }

    /**
     * Register a service provider in `bootstrap/providers.php`. A file that imports its
     * providers (the starter kits) gets a `use` and `Name::class`, sorted the way Pint
     * expects; otherwise the fully qualified class. Null when the file has no list.
     */
    public static function addBootstrapProvider(string $contents, string $provider): ?string
    {
        $provider = ltrim($provider, '\\');
        $basename = class_basename($provider);

        if (str_contains($contents, $provider.'::class') || preg_match('/^use '.preg_quote($provider, '/').';$/m', $contents) === 1) {
            return $contents;
        }

        $imports = preg_match('/^use [^;]+;$/m', $contents) === 1 && preg_match('/\b'.preg_quote($basename, '/').'\b/', $contents) !== 1;
        $entry = $imports ? $basename : $provider;
        $updated = preg_replace('/\];\s*$/', "    {$entry}::class,\n];\n", $contents, 1, $count);

        if ($updated === null || $count !== 1) {
            return null;
        }

        return $imports ? self::addImport($updated, $provider) : $updated;
    }

    /**
     * Sort the top-level class imports alphabetically (Pint's `ordered_imports`), keeping
     * `use function` / `use const` groups after them.
     */
    public static function sortImports(string $contents): string
    {
        [$head, $tail] = self::splitAtClass($contents);

        if (preg_match('/(?:^use [^;]+;\n)+/m', $head, $block, PREG_OFFSET_CAPTURE) !== 1) {
            return $contents;
        }

        $lines = array_values(array_filter(explode("\n", $block[0][0]), static fn (string $line): bool => $line !== ''));
        $classes = array_values(array_unique(array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, 'use function ') && ! str_starts_with($line, 'use const '))));
        $others = array_values(array_diff($lines, $classes));

        usort($classes, static fn (string $a, string $b): int => strcasecmp(str_replace('\\', ' ', rtrim($a, ';')), str_replace('\\', ' ', rtrim($b, ';'))));

        $sorted = implode("\n", [...$classes, ...$others])."\n";

        return substr($head, 0, (int) $block[0][1]).$sorted.substr($head, (int) $block[0][1] + strlen($block[0][0])).$tail;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitAtClass(string $contents): array
    {
        $position = preg_match('/^(?:final |abstract |readonly )*(?:class|interface|trait|enum) |^return new class/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1
            ? (int) $match[0][1]
            : strlen($contents);

        return [substr($contents, 0, $position), substr($contents, $position)];
    }

    /**
     * Chain `->call(...)` onto the panel, right after its `->id(...)` line. `$call` is the
     * call without the arrow, e.g. `realtimeNotifications()`; it may span several lines.
     */
    public static function addCall(string $contents, string $call): ?string
    {
        $method = Str::before($call, '(');

        if (self::hasCall($contents, $method) && ! in_array($method, ['plugin', 'pages', 'authMiddleware', 'routes', 'authenticatedRoutes'], true)) {
            return $contents;
        }

        if (str_contains($contents, '->'.$call)) {
            return $contents;
        }

        if (preg_match('/^([ \t]*)->id\([^\n]*\)[ \t]*$/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $indent = $match[1][0];
            $position = (int) $match[0][1] + strlen($match[0][0]);
            $lines = implode("\n", array_map(static fn (string $line, int $index): string => $index === 0 ? $indent.'->'.$line : $indent.$line, explode("\n", $call), array_keys(explode("\n", $call))));

            return substr($contents, 0, $position)."\n".$lines.substr($contents, $position);
        }

        // `return $panel->id('admin');` on one line, or a chain without `->id()`.
        if (preg_match('/return\s+\$panel\b/', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $position = (int) $match[0][1] + strlen($match[0][0]);

            return substr($contents, 0, $position)."\n            ->".str_replace("\n", "\n            ", $call).substr($contents, $position);
        }

        return null;
    }

    /**
     * Replace the arguments of an existing single `->method(...)` call, or add the call.
     */
    public static function setCall(string $contents, string $method, string $arguments): ?string
    {
        $pattern = '/->'.preg_quote($method, '/').'\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)/';

        if (preg_match($pattern, $contents) === 1) {
            return (string) preg_replace_callback($pattern, static fn (): string => "->{$method}({$arguments})", $contents, 1);
        }

        return self::addCall($contents, "{$method}({$arguments})");
    }

    /**
     * Set one colour in `->colors([...])`, adding the call when there is none.
     */
    public static function setColor(string $contents, string $name, string $value): ?string
    {
        $value = str_replace("'", '', $value);
        $quotedName = preg_quote($name, '/');

        if (preg_match('/->colors\(\s*\[(.*?)\]\s*\)/s', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $inner = $match[1][0];
            $offset = (int) $match[1][1];

            if (preg_match("/'{$quotedName}'\s*=>\s*[^,\n]+/", $inner) === 1) {
                $updated = (string) preg_replace("/'{$quotedName}'\s*=>\s*[^,\n]+/", "'{$name}' => '{$value}'", $inner, 1);
            } else {
                $indent = preg_match('/\n([ \t]+)\S/', $inner, $indentMatch) === 1 ? $indentMatch[1] : '                ';
                $updated = rtrim($inner)."\n{$indent}'{$name}' => '{$value}',\n".substr($indent, 4);
            }

            return substr($contents, 0, $offset).$updated.substr($contents, $offset + strlen($inner));
        }

        return self::addCall($contents, "colors([\n    '{$name}' => '{$value}',\n])");
    }

    /**
     * Register a plugin plus its import, unless the class is already referenced in the file.
     * The plugin is appended to an existing `->plugins([...])` array, so plugins keep the
     * order they were added in; without one, a `->plugins([...])` call is added at the end
     * of the panel's chain.
     */
    public static function addPlugin(string $contents, string $class, string $chain = ''): ?string
    {
        if (self::hasPlugin($contents, $class)) {
            return $contents;
        }

        // `$chain` is PHP written by AlfaKit itself (a bundle's defaults), such as `->navigationGroup('Settings')`.
        $entry = class_basename($class).'::make()'.$chain;
        $updated = self::appendToPluginsArray($contents, $entry) ?? self::appendCall($contents, "plugins([\n    {$entry},\n])");

        return $updated === null ? null : self::addImport($updated, $class);
    }

    /**
     * Append an entry to the first literal `->plugins([...])` array, or null when there is none.
     */
    private static function appendToPluginsArray(string $contents, string $entry): ?string
    {
        if (preg_match('/->plugins\(\s*\[/', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $open = (int) $match[0][1] + strlen($match[0][0]) - 1;
        $close = self::matchingParenthesis($contents, $open, '[', ']');
        $inner = substr($contents, $open + 1, $close - $open - 1);

        if (trim($inner) === '' || trim($inner) === '//') {
            $indent = self::lineIndent($contents, $open);

            return substr($contents, 0, $open + 1)."\n{$indent}    {$entry},\n{$indent}".substr($contents, $close);
        }

        if (! str_contains($inner, "\n")) {
            // `->plugins([A::make(), B::make()])` on one line.
            return substr($contents, 0, $open + 1).rtrim(rtrim($inner), ',').", {$entry}".substr($contents, $close);
        }

        $indent = preg_match('/\n([ \t]*)\S/', $inner, $indentMatch) === 1 ? $indentMatch[1] : self::lineIndent($contents, $open).'    ';
        $body = rtrim($inner);
        $trailing = substr($inner, strlen($body));
        $body = str_ends_with($body, ',') || str_ends_with($body, '[') ? $body : $body.',';

        return substr($contents, 0, $open + 1).$body."\n{$indent}{$entry},".$trailing.substr($contents, $close);
    }

    /**
     * Chain `->call(...)` at the end of the panel's `return $panel…;` statement.
     */
    private static function appendCall(string $contents, string $call): ?string
    {
        if (preg_match('/return\s+\$panel\b/', $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = (int) $match[0][1];
        $end = self::endOfStatement($contents, $start);

        if ($end === null) {
            return null;
        }

        $statement = substr($contents, $start, $end - $start);
        $indent = preg_match_all('/\n([ \t]*)->/', $statement, $indents) > 0
            ? $indents[1][0]
            : self::lineIndent($contents, $start).'    ';
        $lines = implode("\n", array_map(static fn (string $line): string => $indent.$line, explode("\n", '->'.$call)));
        $before = rtrim(substr($contents, 0, $end));

        return $before."\n".$lines.substr($contents, $end);
    }

    /**
     * The offset of the `;` ending the statement that starts at `$start`.
     */
    private static function endOfStatement(string $contents, int $start): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($contents);

        for ($i = $start; $i < $length; $i++) {
            $char = $contents[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($comment = self::endOfComment($contents, $i)) !== null) {
                $i = $comment;
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
            } elseif ($char === ';' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }

    private static function lineIndent(string $contents, int $offset): string
    {
        $lineStart = strrpos(substr($contents, 0, $offset), "\n");
        $line = substr($contents, $lineStart === false ? 0 : $lineStart + 1);

        return preg_match('/\A[ \t]*/', $line, $indent) === 1 ? $indent[0] : '';
    }

    public static function hasPlugin(string $contents, string $class): bool
    {
        return str_contains($contents, class_basename($class).'::make(') || str_contains($contents, ltrim($class, '\\').'::make(');
    }

    /**
     * Unregister a plugin: remove its `->plugin(Name::make()...)` call (or its entry in
     * `->plugins([...])`), with any chained configuration, and its import when unused.
     * Returns the contents unchanged when the plugin isn't registered.
     */
    public static function removePlugin(string $contents, string $class): string
    {
        $class = ltrim($class, '\\');
        $basename = class_basename($class);

        $pattern = '/\\\\?'.preg_quote($class, '/').'::make\(|(?<![\\\\\w])'.preg_quote($basename, '/').'::make\(/';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $contents;
        }

        $start = (int) $match[0][1];
        $end = self::endOfExpression($contents, $start + strlen($match[0][0]) - 1);

        $before = substr($contents, 0, $start);

        if (preg_match('/\s*->plugin\(\s*$/', $before, $call) === 1 && preg_match('/\A\s*\)/', substr($contents, $end), $close) === 1) {
            // `->plugin(Name::make()...)`: drop the whole call.
            $contents = substr($contents, 0, $start - strlen($call[0])).substr($contents, $end + strlen($close[0]));
        } else {
            // An entry of `->plugins([...])`: drop it with its comma, and its line when it had one.
            $after = substr($contents, $end);

            if (preg_match('/\A[ \t]*,[ \t]*/', $after, $comma) === 1) {
                $after = substr($after, strlen($comma[0]));
            } else {
                $before = (string) preg_replace('/,\s*\z/', '', $before);
            }

            if (preg_match('/\n[ \t]*\z/', $before) === 1 && str_starts_with($after, "\n")) {
                $before = (string) preg_replace('/\n[ \t]*\z/', '', $before);
            }

            $contents = $before.$after;
        }

        if (! str_contains(preg_replace('/^use [^;]+;$/m', '', $contents) ?? '', $basename)) {
            $contents = (string) preg_replace('/^use '.preg_quote($class, '/').';\n/m', '', $contents);
            $contents = (string) preg_replace('/\n{3,}/', "\n\n", $contents);
        }

        // Nothing left in `->plugins([...])`.
        return (string) preg_replace('/\n?[ \t]*->plugins\(\[\s*\]\)/', '', $contents);
    }

    /**
     * From the `(` of a call, the offset just after the call and any chained `->method(...)`.
     */
    private static function endOfExpression(string $contents, int $open): int
    {
        $position = self::matchingParenthesis($contents, $open) + 1;

        while (preg_match('/\G\s*->\w+\(/', $contents, $chain, 0, $position) === 1) {
            $position = self::matchingParenthesis($contents, $position + strlen($chain[0]) - 1) + 1;
        }

        return $position;
    }

    private static function matchingParenthesis(string $contents, int $open, string $opening = '(', string $closing = ')'): int
    {
        $depth = 0;
        $length = strlen($contents);
        $quote = null;

        for ($i = $open; $i < $length; $i++) {
            $char = $contents[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($comment = self::endOfComment($contents, $i)) !== null) {
                $i = $comment;
            } elseif ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === $opening) {
                $depth++;
            } elseif ($char === $closing && --$depth === 0) {
                return $i;
            }
        }

        return $length - 1;
    }

    /**
     * When a comment (line comments with `//` or `#`, or a block comment) starts at `$offset`, the offset of its last
     * character, so scanners skip it (an apostrophe in a comment is not a string); else null.
     */
    private static function endOfComment(string $contents, int $offset): ?int
    {
        $pair = substr($contents, $offset, 2);

        if ($pair === '//' || ($contents[$offset] === '#' && $pair !== '#[')) {
            $newline = strpos($contents, "\n", $offset);

            return $newline === false ? strlen($contents) - 1 : $newline - 1;
        }

        if ($pair === '/*') {
            $close = strpos($contents, '*/', $offset + 2);

            return $close === false ? strlen($contents) - 1 : $close + 1;
        }

        return null;
    }
}
