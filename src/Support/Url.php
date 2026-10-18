<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Stringable;

/**
 * Scheme allowlist for URLs that the renderers put into `href` / `window.open()`.
 *
 * Allowed: `http:`, `https:`, `mailto:`, `tel:`, relative paths (`/admin`, `posts/1`,
 * `?page=2`) and fragments (`#section`). Everything else (`javascript:`, `data:`,
 * `vbscript:`, `file:`, ...) returns null, so the component renders without a link.
 * Browsers ignore whitespace and control characters inside a scheme and decode HTML
 * entities in attributes, so both are stripped before the scheme is checked.
 */
final class Url
{
    /** @var list<string> */
    public const array ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * @param  list<string>|null  $schemes  Defaults to {@see self::ALLOWED_SCHEMES}.
     */
    public static function sanitize(mixed $url, ?array $schemes = null): ?string
    {
        if ($url instanceof Stringable) {
            $url = (string) $url;
        }

        if (! is_string($url) || ($url = trim($url)) === '') {
            return null;
        }

        if (self::isSafe($url, $schemes ?? self::ALLOWED_SCHEMES)) {
            return $url;
        }

        return null;
    }

    /**
     * @param  list<string>  $schemes
     */
    public static function isSafe(string $url, array $schemes = self::ALLOWED_SCHEMES): bool
    {
        $probe = $url;

        // Entities are decoded by the browser before the URL is parsed (`javascript&colon;`).
        // Decode repeatedly so double-encoded payloads cannot slip through either.
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($probe, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if ($decoded === $probe) {
                break;
            }

            $probe = $decoded;
        }

        // Whitespace, control characters and invisible format characters inside the
        // scheme are ignored by browsers (`java\tscript:`, `\u{200B}javascript:`).
        $probe = (string) preg_replace('/[\x00-\x20\x7F]|\xC2[\x80-\xA0]|\xE2\x80[\x80-\x8F\xA8-\xAF]|\xE2\x81[\x9F-\xAF]|\xEF\xBB\xBF/', '', $probe);

        // Protocol-relative (`//evil.test`) and backslash variants (`/\evil.test`) leave
        // the panel's origin: allowed only when absolute http(s) is.
        if (preg_match('#^[/\\\\]{2}#', $probe) === 1) {
            return in_array('https', $schemes, true) || in_array('http', $schemes, true);
        }

        // A colon before the first `/`, `?` or `#` makes the browser read a scheme.
        if (preg_match('/^([^\/?#]*):/', $probe, $match) !== 1) {
            return true;
        }

        $scheme = strtolower($match[1]);

        return preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) === 1 && in_array($scheme, $schemes, true);
    }
}
