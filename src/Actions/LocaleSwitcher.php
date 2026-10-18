<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Localization\TranslatableLocale;

/**
 * A header action that sets the language every `->translatable()` field on the page
 * opens on (`LocaleSwitcher::make()` in `getHeaderActions()`). The choice is kept in the
 * session per panel, so it follows the user from page to page.
 *
 * The languages default to the panel's locales; pass codes, `code => label` pairs or
 * `Locale`s to offer others (matching what the fields are translatable in).
 *
 * ```php
 * protected function getHeaderActions(): array
 * {
 *     return [LocaleSwitcher::make(), EditAction::make()];
 * }
 * ```
 */
class LocaleSwitcher extends ActionGroup
{
    public const ACTION_PREFIX = 'translatableLocale-';

    /**
     * @param  array<int|string, string|Locale>  $locales
     */
    public static function make(array $locales = []): static
    {
        $resolved = static::resolveLocales($locales);
        $current = static::getCurrentLocale($resolved);

        $actions = array_values(array_map(static fn (Locale $locale): Action => Action::make(self::ACTION_PREFIX.$locale->getCode())
            ->label($locale->getLabel())
            ->icon($locale->getCode() === $current ? 'check' : null)
            ->extraAttributes(['lang' => $locale->getTag(), 'dir' => $locale->getDirection()->value])
            ->action(static function () use ($locale): void {
                TranslatableLocale::set($locale->getCode());
            }), $resolved));

        $static = new static($actions);
        $static->configure();

        return $static
            ->label(static fn (): string => isset($resolved[$current]) ? $resolved[$current]->getLabel() : __('alfakit::forms.translatable.editing_language'))
            ->tooltip(__('alfakit::forms.translatable.editing_language'))
            ->icon('languages')
            ->button();
    }

    /**
     * @param  array<int|string, string|Locale>  $locales
     * @return array<string, Locale> keyed by code
     */
    protected static function resolveLocales(array $locales): array
    {
        if ($locales === []) {
            return alfakit()->getCurrentOrDefaultPanel()->getLocales();
        }

        $resolved = [];

        foreach ($locales as $key => $value) {
            $locale = match (true) {
                $value instanceof Locale => $value,
                is_string($key) => Locale::make($key)->label((string) $value),
                default => Locale::make((string) $value),
            };

            $resolved[$locale->getCode()] = $locale;
        }

        return $resolved;
    }

    /**
     * @param  array<string, Locale>  $locales
     */
    protected static function getCurrentLocale(array $locales): string
    {
        $current = TranslatableLocale::get();

        if ($current !== null && isset($locales[$current])) {
            return $current;
        }

        $default = alfakit()->getCurrentOrDefaultPanel()->getDefaultLocale()->getCode();

        return isset($locales[$default]) ? $default : (string) array_key_first($locales);
    }
}
