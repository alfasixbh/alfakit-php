<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use AlfaSix\Alfakit\Panel;

/**
 * The language a user is editing translatable content in: the session value set by a
 * `LocaleSwitcher` action, per panel. Every `->translatable()` field on a page opens on it
 * (when the field offers that language); otherwise fields open on the panel's default.
 */
final class TranslatableLocale
{
    public static function get(?Panel $panel = null): ?string
    {
        $locale = session()->get(self::sessionKey($panel));

        return is_string($locale) && $locale !== '' ? Locale::normalize($locale) : null;
    }

    public static function set(?string $locale, ?Panel $panel = null): void
    {
        if ($locale === null || $locale === '') {
            session()->forget(self::sessionKey($panel));

            return;
        }

        session()->put(self::sessionKey($panel), Locale::normalize($locale));
    }

    public static function sessionKey(?Panel $panel = null): string
    {
        $panel ??= alfakit()->getCurrentOrDefaultPanel();

        return 'alfakit.'.$panel->getId().'.translatable_locale';
    }
}
