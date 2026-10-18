<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pwa;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Colors\Color;
use GdImage;
use Throwable;

/**
 * Manifest icons for `Panel::pwa()`: normalises `pwa(icons: …)` and generates the
 * fallback set (192, 512 and a maskable 512 PNG) from the favicon or brand logo, or from
 * the brand's initial on the theme colour.
 */
final class PwaIcons
{
    /** Bump to regenerate cached icons after a change in how they are drawn. */
    private const VERSION = '1';

    /** Fonts tried for the initial; GD's built-in font is the last resort. */
    private const FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        '/Library/Fonts/Arial Bold.ttf',
        'C:\\Windows\\Fonts\\arialbd.ttf',
    ];

    /**
     * `['192x192' => '/icon.png']` or full manifest icon arrays.
     *
     * @param  array<int|string, mixed>  $icons
     * @return array<int, array<string, string>>
     */
    public static function normalize(array $icons): array
    {
        $normalized = [];

        foreach ($icons as $key => $icon) {
            if (is_string($icon)) {
                $icon = ['src' => $icon, ...(is_string($key) ? ['sizes' => $key] : [])];
            }

            if (! is_array($icon) || ! is_string($icon['src'] ?? null) || $icon['src'] === '') {
                continue;
            }

            $src = $icon['src'];
            $entry = ['src' => $src];

            $sizes = $icon['sizes'] ?? (self::extension($src) === 'svg' ? 'any' : null);

            if (is_string($sizes) && $sizes !== '') {
                $entry['sizes'] = $sizes;
            }

            $type = $icon['type'] ?? self::mimeType($src);

            if (is_string($type) && $type !== '') {
                $entry['type'] = $type;
            }

            if (is_string($icon['purpose'] ?? null) && $icon['purpose'] !== '') {
                $entry['purpose'] = $icon['purpose'];
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * The fallback set: an SVG favicon or logo as-is, plus generated PNGs (with GD).
     *
     * @return array<int, array<string, string>>
     */
    public static function defaults(Panel $panel): array
    {
        $icons = [];

        $svg = self::svgSource($panel);

        if ($svg !== null) {
            $icons[] = ['src' => $svg, 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
        }

        if (self::canDraw()) {
            $icons[] = ['src' => $panel->route('pwa.icon', ['icon' => '192.png'], false), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $panel->route('pwa.icon', ['icon' => '512.png'], false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'];
            $icons[] = ['src' => $panel->route('pwa.icon', ['icon' => 'maskable-512.png'], false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        } elseif ($svg === null) {
            $icons[] = ['src' => $panel->route('pwa.icon', ['icon' => 'monogram.svg'], false), 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'];
        }

        return $icons;
    }

    public static function canDraw(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /**
     * A generated icon: `192.png`, `512.png`, `maskable-512.png` or `monogram.svg`.
     *
     * @return array{0: string, 1: string}|null [body, content type]
     */
    public static function render(Panel $panel, string $icon): ?array
    {
        if ($icon === 'monogram.svg') {
            return [self::monogramSvg($panel), 'image/svg+xml'];
        }

        if (! self::canDraw() || preg_match('/^(maskable-)?(192|512)\.png$/', $icon, $matches) !== 1) {
            return null;
        }

        $maskable = $matches[1] !== '';
        $size = (int) $matches[2];
        $source = self::rasterSource($panel);
        $color = $panel->getPwaThemeColor();

        $key = md5(implode('|', [self::VERSION, $panel->getId(), $icon, $color, self::initial($panel), $source ?? '', $source !== null ? (string) @filemtime($source) : '']));
        $directory = storage_path('framework/cache/alfakit-pwa');
        $cached = "{$directory}/{$key}.png";

        if (is_file($cached) && ($body = @file_get_contents($cached)) !== false) {
            return [$body, 'image/png'];
        }

        $body = self::drawPng($size, $maskable, $color, self::initial($panel), $source);

        if ($body === null) {
            return null;
        }

        if (! is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        @file_put_contents($cached, $body);

        return [$body, 'image/png'];
    }

    public static function monogramSvg(Panel $panel): string
    {
        $color = htmlspecialchars($panel->getPwaThemeColor(), ENT_QUOTES);
        $foreground = self::foreground($panel->getPwaThemeColor());
        $initial = htmlspecialchars(self::initial($panel), ENT_QUOTES);

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="'.$color.'"/>'
            .'<text x="256" y="256" dy=".35em" text-anchor="middle" font-family="system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif" font-size="280" font-weight="700" fill="'.$foreground.'">'.$initial.'</text></svg>';
    }

    private static function initial(Panel $panel): string
    {
        $name = trim($panel->getPwaName());

        return $name === '' ? 'A' : mb_strtoupper(mb_substr($name, 0, 1));
    }

    private static function foreground(string $color): string
    {
        try {
            return Color::isLight($color) ? '#171717' : '#ffffff';
        } catch (Throwable) {
            return '#ffffff';
        }
    }

    private static function svgSource(Panel $panel): ?string
    {
        foreach ([$panel->getFavicon(), self::brandLogo($panel)] as $url) {
            if (is_string($url) && $url !== '' && self::extension($url) === 'svg') {
                return $url;
            }
        }

        return null;
    }

    /** A local PNG / JPEG / WebP / GIF favicon or logo to draw the PNG icons from. */
    private static function rasterSource(Panel $panel): ?string
    {
        foreach ([$panel->getFavicon(), self::brandLogo($panel)] as $url) {
            if (! is_string($url) || $url === '' || ! in_array(self::extension($url), ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                continue;
            }

            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && app()->bound('request') && $host !== request()->getHost()) {
                continue;
            }

            $path = public_path(ltrim((string) parse_url($url, PHP_URL_PATH), '/'));
            $real = realpath($path);
            $public = realpath(public_path());

            if ($real !== false && $public !== false && str_starts_with($real, $public) && is_file($real)) {
                return $real;
            }
        }

        return null;
    }

    private static function brandLogo(Panel $panel): mixed
    {
        $schema = (fn (): mixed => $this->evaluate($this->brandLogo))->call($panel);

        return is_string($schema) ? $schema : null;
    }

    private static function extension(string $url): string
    {
        return strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    }

    private static function mimeType(string $url): ?string
    {
        return match (self::extension($url)) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'jpg', 'jpeg' => 'image/jpeg',
            'ico' => 'image/x-icon',
            'gif' => 'image/gif',
            default => null,
        };
    }

    private static function drawPng(int $size, bool $maskable, string $color, string $initial, ?string $source): ?string
    {
        $image = imagecreatetruecolor($size, $size);
        imagesavealpha($image, true);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagealphablending($image, true);

        [$r, $g, $b] = self::rgb($color);
        $background = (int) imagecolorallocate($image, $r, $g, $b);

        $sourceImage = $source !== null ? self::load($source) : null;

        if ($sourceImage instanceof GdImage && ! $maskable) {
            // A square favicon fills the icon; anything else is centred with a margin.
            self::paste($image, $sourceImage, $size, imagesx($sourceImage) === imagesy($sourceImage) ? 1.0 : 0.8);
        } else {
            if ($maskable) {
                imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $background);
            } else {
                self::roundedRectangle($image, $size, (int) round($size * 0.1875), $background);
            }

            if ($sourceImage instanceof GdImage) {
                // Maskable icons keep their content inside the central 80% safe zone.
                self::paste($image, $sourceImage, $size, 0.6);
            } else {
                self::drawInitial($image, $size, $initial, self::foreground($color), $maskable ? 0.42 : 0.52);
            }
        }

        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean() ?: null;
    }

    private static function load(string $path): ?GdImage
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        try {
            $image = @imagecreatefromstring($contents);
        } catch (Throwable) {
            return null;
        }

        return $image instanceof GdImage ? $image : null;
    }

    private static function paste(GdImage $canvas, GdImage $source, int $size, float $scale): void
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $box = $size * $scale;
        $ratio = min($box / max(1, $width), $box / max(1, $height));
        $targetWidth = (int) round($width * $ratio);
        $targetHeight = (int) round($height * $ratio);

        imagecopyresampled($canvas, $source, (int) (($size - $targetWidth) / 2), (int) (($size - $targetHeight) / 2), 0, 0, $targetWidth, $targetHeight, $width, $height);
    }

    private static function roundedRectangle(GdImage $image, int $size, int $radius, int $color): void
    {
        imagefilledrectangle($image, $radius, 0, $size - 1 - $radius, $size - 1, $color);
        imagefilledrectangle($image, 0, $radius, $size - 1, $size - 1 - $radius, $color);

        foreach ([[$radius, $radius], [$size - 1 - $radius, $radius], [$radius, $size - 1 - $radius], [$size - 1 - $radius, $size - 1 - $radius]] as [$x, $y]) {
            imagefilledellipse($image, $x, $y, $radius * 2, $radius * 2, $color);
        }
    }

    private static function drawInitial(GdImage $image, int $size, string $initial, string $hex, float $scale): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $color = (int) imagecolorallocate($image, $r, $g, $b);
        $font = function_exists('imagettftext') ? self::font() : null;

        if ($font !== null) {
            $points = $size * $scale * 0.75;
            $box = imagettfbbox($points, 0, $font, $initial);

            if ($box !== false) {
                $width = $box[2] - $box[0];
                $height = $box[1] - $box[7];
                imagettftext($image, $points, 0, (int) (($size - $width) / 2 - $box[0]), (int) (($size + $height) / 2 - $box[1]), $color, $font, $initial);

                return;
            }
        }

        if (preg_match('/^[\x20-\x7E]$/', $initial) !== 1) {
            return;
        }

        // GD's built-in 9×15 font, scaled up.
        $glyph = imagecreatetruecolor(9, 15);
        imagesavealpha($glyph, true);
        imagealphablending($glyph, false);
        imagefill($glyph, 0, 0, (int) imagecolorallocatealpha($glyph, 0, 0, 0, 127));
        imagestring($glyph, 5, 0, 0, $initial, (int) imagecolorallocate($glyph, $r, $g, $b));
        $height = (int) round($size * $scale);
        $width = (int) round($height * 9 / 15);
        imagealphablending($image, true);
        imagecopyresized($image, $glyph, (int) (($size - $width) / 2), (int) (($size - $height) / 2), 0, 0, $width, $height, 9, 15);
    }

    private static function font(): ?string
    {
        foreach (self::FONTS as $font) {
            if (@is_file($font)) {
                return $font;
            }
        }

        return null;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $color): array
    {
        try {
            $hex = ltrim(Color::convertToHex($color), '#');
        } catch (Throwable) {
            $hex = '171717';
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
