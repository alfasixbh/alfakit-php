<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Colors;

/**
 * Allowlist for CSS colours that end up in inline styles (`ColorColumn`, `ColorEntry`,
 * `Str::sanitizeCssColor()`): hex, the colour functions with plain numeric arguments
 * (`rgb()`, `hsl()`, `hwb()`, `lab()`, `lch()`, `oklab()`, `oklch()`) and keywords.
 * Anything else (`url()`, `var()`, `expression()`, declarations) returns null.
 */
final class CssColor
{
    public static function sanitize(mixed $color): ?string
    {
        if (! is_string($color)) {
            return null;
        }

        $color = trim($color);

        return self::isSafe($color) ? $color : null;
    }

    public static function isSafe(string $color): bool
    {
        $color = trim($color);

        return preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $color) === 1
            || preg_match('/^(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(\s*[0-9.%\s,\/a-z+-]{1,80}\)$/i', $color) === 1
                && preg_match('/\(\s*(?:[0-9.+-]+(?:%|deg|rad|turn|grad)?|none)(?:[\s,\/]+(?:[0-9.+-]+(?:%|deg|rad|turn|grad)?|none))*\s*\)$/i', $color) === 1
            || preg_match('/^[a-z]{3,20}$/i', $color) === 1;
    }
}
