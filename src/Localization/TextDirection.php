<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use ResourceBundle;
use Throwable;

/**
 * Writing direction of a locale.
 *
 * Detection works for any locale code, not just a fixed list:
 *
 * 1. An explicit script subtag wins (`pa_Arab` is RTL, `sr_Latn` is LTR).
 * 2. Languages whose default script is right-to-left (Arabic, Hebrew, Persian, Urdu,
 *    Dhivehi, …) are RTL.
 * 3. Otherwise the ICU locale data bundled with ext-intl decides.
 */
enum TextDirection: string
{
    case Ltr = 'ltr';
    case Rtl = 'rtl';

    /** ISO 15924 codes of scripts written right-to-left. */
    private const RTL_SCRIPTS = [
        'Adlm', 'Arab', 'Aran', 'Hebr', 'Mand', 'Mend', 'Nkoo', 'Rohg', 'Samr', 'Syrc', 'Thaa', 'Yezi',
    ];

    /** Languages whose default script is right-to-left (ISO 639). */
    private const RTL_LANGUAGES = [
        'ar', 'arc', 'arz', 'azb', 'bal', 'bqi', 'ckb', 'dv', 'fa', 'glk', 'he', 'iw', 'khw', 'ks',
        'lrc', 'mzn', 'nqo', 'pnb', 'ps', 'prs', 'sd', 'sdh', 'skr', 'syr', 'ug', 'ur', 'yi',
    ];

    public static function detect(string $locale): self
    {
        $locale = str_replace('-', '_', $locale);
        $parts = explode('_', $locale);
        $language = strtolower($parts[0]);

        foreach (array_slice($parts, 1) as $part) {
            if (strlen($part) === 4 && ctype_alpha($part)) {
                return in_array(ucfirst(strtolower($part)), self::RTL_SCRIPTS, true) ? self::Rtl : self::Ltr;
            }
        }

        if (in_array($language, self::RTL_LANGUAGES, true)) {
            return self::Rtl;
        }

        return self::fromIcu($locale) ?? self::Ltr;
    }

    public function isRtl(): bool
    {
        return $this === self::Rtl;
    }

    private static function fromIcu(string $locale): ?self
    {
        if (! class_exists(ResourceBundle::class)) {
            return null;
        }

        try {
            $layout = ResourceBundle::create($locale, 'ICUDATA')?->get('layout');
            $characters = $layout instanceof ResourceBundle ? $layout->get('characters') : null;
        } catch (Throwable) {
            return null;
        }

        return match ($characters) {
            'right-to-left' => self::Rtl,
            'left-to-right' => self::Ltr,
            default => null,
        };
    }
}
