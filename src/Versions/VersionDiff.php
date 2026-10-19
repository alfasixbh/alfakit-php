<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Versions;

use AlfaSix\Alfakit\Support\Labels;

/**
 * Field-by-field changes between two snapshots, for the history slide-over. Values are
 * turned into display text on the server; long text (and rich text, compared as its
 * text) also gets a word diff: `[{type: 'equal'|'insert'|'delete', text}]`.
 */
final class VersionDiff
{
    /**
     * Word diffs are skipped when the changed middle part has more words than this per side
     * (the plain old/new values stay).
     */
    private const MAX_WORDS = 400;

    /**
     * @param  array<string, mixed>|null  $old  The previous snapshot (null for the first version)
     * @param  array<string, mixed>  $new
     * @param  array<string, string>  $labels  Attribute labels
     * @return array<int, array{attribute: string, label: string, old: string|null, new: string|null, diff?: array<int, array{type: string, text: string}>}>
     */
    public static function compare(?array $old, array $new, array $labels = []): array
    {
        $changes = [];

        foreach (array_unique([...array_keys($old ?? []), ...array_keys($new)]) as $attribute) {
            $before = $old === null ? null : ($old[$attribute] ?? null);
            $after = $new[$attribute] ?? null;

            if ($old !== null && self::same($before, $after)) {
                continue;
            }

            $oldText = self::toText($before);
            $newText = self::toText($after);

            if ($old === null && $newText === null) {
                continue;
            }

            $change = [
                'attribute' => (string) $attribute,
                'label' => $labels[$attribute] ?? Labels::fromName((string) $attribute, isRelationshipPath: false),
                'old' => $oldText,
                'new' => $newText,
            ];

            if ($oldText !== null && $newText !== null && self::isLongText($oldText, $newText)) {
                $diff = self::words($oldText, $newText);

                if ($diff !== null) {
                    $change['diff'] = $diff;
                }
            }

            $changes[] = $change;
        }

        return $changes;
    }

    /**
     * A display string for a raw value: rich text becomes its text, JSON is shown compact,
     * null stays null.
     */
    public static function toText(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $text = (string) $value;

        if (self::isHtml($text)) {
            return self::htmlToText($text);
        }

        return $text;
    }

    /**
     * Word-level diff (LCS); null when either side is too long to compare.
     *
     * @return array<int, array{type: string, text: string}>|null
     */
    public static function words(string $old, string $new): ?array
    {
        $a = self::tokenize($old);
        $b = self::tokenize($new);

        // The unchanged start and end are compared cheaply; only the middle needs the table.
        $prefix = [];

        while ($a !== [] && $b !== [] && $a[0] === $b[0]) {
            $prefix[] = array_shift($a);
            array_shift($b);
        }

        $suffix = [];

        while ($a !== [] && $b !== [] && $a[count($a) - 1] === $b[count($b) - 1]) {
            array_unshift($suffix, array_pop($a));
            array_pop($b);
        }

        $n = count($a);
        $m = count($b);

        if ($n > self::MAX_WORDS || $m > self::MAX_WORDS) {
            return null;
        }

        // Longest common subsequence table, from the end.
        $lengths = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $a[$i] === $b[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $parts = [];
        $push = static function (string $type, string $text) use (&$parts): void {
            $last = array_key_last($parts);

            if ($last !== null && $parts[$last]['type'] === $type) {
                $parts[$last]['text'] .= $text;
            } else {
                $parts[] = ['type' => $type, 'text' => $text];
            }
        };

        if ($prefix !== []) {
            $push('equal', implode('', $prefix));
        }

        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $push('equal', $a[$i]);
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $push('delete', $a[$i]);
                $i++;
            } else {
                $push('insert', $b[$j]);
                $j++;
            }
        }

        for (; $i < $n; $i++) {
            $push('delete', $a[$i]);
        }

        for (; $j < $m; $j++) {
            $push('insert', $b[$j]);
        }

        if ($suffix !== []) {
            $push('equal', implode('', $suffix));
        }

        return $parts;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a === $b;
    }

    private static function isLongText(string $old, string $new): bool
    {
        return str_contains($old, ' ') || str_contains($new, ' ') || mb_strlen($old) > 40 || mb_strlen($new) > 40;
    }

    private static function isHtml(string $text): bool
    {
        return str_contains($text, '<') && $text !== strip_tags($text);
    }

    private static function htmlToText(string $html): string
    {
        // Block ends become spaces so words of adjacent paragraphs don't merge.
        $html = (string) preg_replace('/<\s*(br|\/p|\/div|\/li|\/h[1-6]|\/blockquote|\/tr)\b[^>]*>/i', ' ', $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Words with their trailing whitespace, so the parts join back to the text.
     *
     * @return array<int, string>
     */
    private static function tokenize(string $text): array
    {
        preg_match_all('/\S+\s*|\s+/u', $text, $matches);

        return $matches[0];
    }
}
