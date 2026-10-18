<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

/**
 * Picks a readable text colour (near white or near black) for a brand colour, so a pale
 * `->colors(['primary' => '#FDE68A'])` gets dark button text instead of white.
 *
 * Understands hex (`#rgb`, `#rgba`, `#rrggbb`, `#rrggbbaa`), `rgb()` / `rgba()` and
 * `oklch()`. Anything else (named colours, `var()`, `color-mix()`) returns null and the
 * theme's own foreground stays in place.
 */
final class ColorContrast
{
    public const string LIGHT = 'oklch(0.985 0 0)';

    public const string DARK = 'oklch(0.205 0 0)';

    public static function foreground(string $color): ?string
    {
        $luminance = self::luminance($color);

        if ($luminance === null) {
            return null;
        }

        // WCAG contrast against the two candidates; pick whichever reads better.
        $againstLight = (0.9619 + 0.05) / ($luminance + 0.05);
        $againstDark = ($luminance + 0.05) / (0.0187 + 0.05);

        return $againstDark > $againstLight ? self::DARK : self::LIGHT;
    }

    /**
     * WCAG relative luminance in [0, 1], or null when the colour can't be parsed.
     */
    public static function luminance(string $color): ?float
    {
        $color = strtolower(trim($color));

        if (preg_match('/^oklch\(\s*([\d.]+)(%?)/', $color, $m) === 1) {
            $l = (float) $m[1] / ($m[2] === '%' ? 100 : 1);

            // OKLab lightness is the cube root of luminance (for neutral colours exactly,
            // and close enough for chroma to decide between black and white text).
            return max(0.0, min(1.0, $l ** 3));
        }

        $rgb = self::rgb($color);

        if ($rgb === null) {
            return null;
        }

        [$r, $g, $b] = array_map(static function (float $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * @return array{float, float, float}|null
     */
    private static function rgb(string $color): ?array
    {
        if (preg_match('/^#([0-9a-f]{3,8})$/', $color, $m) === 1) {
            $hex = $m[1];

            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }

            if (strlen($hex) !== 6 && strlen($hex) !== 8) {
                return null;
            }

            return [(float) hexdec(substr($hex, 0, 2)), (float) hexdec(substr($hex, 2, 2)), (float) hexdec(substr($hex, 4, 2))];
        }

        if (preg_match('/^rgba?\(\s*([\d.]+%?)[\s,]+([\d.]+%?)[\s,]+([\d.]+%?)/', $color, $m) === 1) {
            $channel = static fn (string $v): float => str_ends_with($v, '%') ? (float) $v * 2.55 : (float) $v;

            return [$channel($m[1]), $channel($m[2]), $channel($m[3])];
        }

        return null;
    }
}
