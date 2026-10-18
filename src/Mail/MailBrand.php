<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Mail;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Colors\Color;
use Throwable;

/**
 * The brand of AlfaKit's emails: the panel's name, logo, primary colour and font, as
 * values email clients understand (absolute image URLs, hex colours, font stacks).
 *
 * Resolved from `config('alfakit.mail.*')` first, then the panel (`alfakit.mail.panel`,
 * else the current panel, else the default one). Emails sent from a queue have no
 * current panel, so they follow the default panel unless `alfakit.mail.panel` says
 * otherwise. Replace the whole resolution with `MailTheme::brandUsing()`.
 */
final readonly class MailBrand
{
    public const DEFAULT_PRIMARY = '#073b78';

    /** Web-safe fallbacks after the panel font; Tahoma and Segoe UI cover Arabic and Persian. */
    public const FONT_FALLBACKS = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, Tahoma, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji'";

    public function __construct(
        public string $name,
        public ?string $url = null,
        public ?string $logo = null,
        public ?string $darkLogo = null,
        public int $logoHeight = 32,
        public string $primary = self::DEFAULT_PRIMARY,
        public string $font = self::FONT_FALLBACKS,
    ) {}

    public static function resolve(?Panel $panel = null): self
    {
        $panel ??= self::panel();
        $config = static fn (string $key): mixed => config('alfakit.mail.'.$key);

        $logo = self::imageUrl($config('logo')) ?? self::imageUrl($panel?->getBrandLogo(), skipSvg: true);
        $darkLogo = self::imageUrl($config('dark_logo')) ?? self::imageUrl($panel?->getDarkModeBrandLogo(), skipSvg: true);
        $font = $config('font') ?? $panel?->getFont();
        $url = $config('url') ?? self::panelUrl($panel) ?? config('app.url');
        $height = $config('logo_height');

        return new self(
            name: self::string($config('name')) ?? $panel?->getBrandName() ?? (string) config('app.name', 'AlfaKit'),
            url: is_string($url) && $url !== '' ? $url : null,
            logo: $logo,
            darkLogo: $darkLogo,
            logoHeight: is_numeric($height) ? max(16, min(96, (int) $height)) : 32,
            primary: self::hex($config('primary_color')) ?? self::panelPrimary($panel) ?? self::DEFAULT_PRIMARY,
            font: is_string($font) && $font !== '' && preg_match('/^[\w\s\-]+$/u', $font) === 1
                ? "'".$font."', ".self::FONT_FALLBACKS
                : self::FONT_FALLBACKS,
        );
    }

    /**
     * Readable text on the primary colour (white or near-black, WCAG AA).
     */
    public function primaryForeground(): string
    {
        try {
            return Color::calculateContrastRatio('#ffffff', $this->primary) >= Color::WCAG_AA_TEXT ? '#ffffff' : '#111827';
        } catch (Throwable) {
            return '#ffffff';
        }
    }

    /**
     * The primary colour as link text on dark backgrounds: the primary itself when it
     * reads on the dark surface, else a light tint of its hue.
     */
    public function primaryOnDark(): string
    {
        try {
            if (Color::calculateContrastRatio($this->primary, '#111827') >= Color::WCAG_AA_TEXT) {
                return $this->primary;
            }

            $palette = Color::generatePalette($this->primary);

            return Color::convertToHex($palette[300] ?? $palette[400]);
        } catch (Throwable) {
            return '#93c5fd';
        }
    }

    private static function panel(): ?Panel
    {
        $id = config('alfakit.mail.panel');

        if (is_string($id) && alfakit()->hasPanel($id)) {
            return alfakit()->getPanel($id);
        }

        return alfakit()->getCurrentPanel() ?? alfakit()->getDefaultPanel();
    }

    private static function panelUrl(?Panel $panel): ?string
    {
        try {
            return $panel?->getUrl();
        } catch (Throwable) {
            return null;
        }
    }

    private static function panelPrimary(?Panel $panel): ?string
    {
        $primary = $panel?->getColors()['primary'] ?? null;

        try {
            if (Color::isPalette($primary)) {
                /** @var array<int, string> $primary */
                return Color::convertToHex($primary[Color::getBaseShade($primary)]);
            }
        } catch (Throwable) {
            return null;
        }

        return self::hex($primary);
    }

    private static function hex(mixed $color): ?string
    {
        if (! is_string($color) || trim($color) === '') {
            return null;
        }

        try {
            return Color::convertToHex($color);
        } catch (Throwable) {
            return null;
        }
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * An absolute http(s) URL for an image, or null (inline SVG markup, `data:` URIs and,
     * for panel logos, SVG files are skipped: most email clients don't show them).
     */
    private static function imageUrl(mixed $value, bool $skipSvg = false): ?string
    {
        if (! is_string($value) || ($value = trim($value)) === '' || str_starts_with($value, '<')) {
            return null;
        }

        if ($skipSvg && preg_match('/\.svg(\?.*)?$/i', $value) === 1) {
            return null;
        }

        if (! preg_match('#^https?://#i', $value)) {
            if (str_contains($value, ':')) {
                return null;
            }

            $value = url($value);
        }

        return $value;
    }
}
