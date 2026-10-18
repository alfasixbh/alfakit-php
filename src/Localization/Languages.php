<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use DateTimeImmutable;
use DateTimeZone;
use ResourceBundle;
use Throwable;

/**
 * Option lists for language and timezone pickers, labelled in the user's language.
 */
final class Languages
{
    /**
     * `['ar' => 'Arabic · العربية', 'de' => 'German · Deutsch', …]`. Without `$only`, every
     * language ICU has data for (language subtags only, no regional variants).
     *
     * @param  array<int, string>|null  $only
     * @return array<string, string>
     */
    public static function options(?array $only = null, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $codes = $only === null ? self::codes() : array_map(Locale::normalize(...), $only);
        $options = [];

        foreach ($codes as $code) {
            $language = Locale::make($code);
            $inUserLanguage = $language->getLabelIn($locale);
            $native = $language->getLabel();

            $options[$code] = $inUserLanguage === $native ? $native : "{$inUserLanguage} · {$native}";
        }

        if ($only === null) {
            asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    public static function codes(): array
    {
        try {
            $locales = ResourceBundle::getLocales('') ?: [];
        } catch (Throwable) {
            $locales = [];
        }

        $codes = array_values(array_unique(array_filter($locales, static fn (string $code): bool => ! str_contains($code, '_'))));
        sort($codes);

        return $codes;
    }

    /**
     * `['Asia/Bahrain' => 'Asia/Bahrain (UTC+03:00)', …]`, ordered by offset then name.
     *
     * @param  array<int, string>|null  $only
     * @return array<string, string>
     */
    public static function timezones(?array $only = null): array
    {
        $now = new DateTimeImmutable('now');
        $zones = [];

        foreach ($only ?? DateTimeZone::listIdentifiers() as $identifier) {
            try {
                $offset = (new DateTimeZone($identifier))->getOffset($now);
            } catch (Throwable) {
                continue;
            }

            $zones[$identifier] = $offset;
        }

        if ($only === null) {
            uksort($zones, static fn (string $a, string $b): int => [$zones[$a], $a] <=> [$zones[$b], $b]);
        }

        $options = [];

        foreach ($zones as $identifier => $offset) {
            $sign = $offset < 0 ? '-' : '+';
            $offset = abs($offset);
            $options[$identifier] = sprintf('%s (UTC%s%02d:%02d)', str_replace('_', ' ', $identifier), $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60));
        }

        return $options;
    }
}
