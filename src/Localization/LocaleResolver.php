<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Request;

/**
 * Picks the locale for a panel request. First match wins:
 *
 * 1. `Panel::resolveLocaleUsing()` callback
 * 2. the language chosen in the switcher during this session
 * 3. the signed-in user's `preferredLocale()` (Laravel's `HasLocalePreference`)
 * 4. the `alfakit_locale` cookie (remembers guests between sessions)
 * 5. the browser's `Accept-Language` header
 * 6. the panel's default locale
 *
 * Every candidate is matched loosely against the panel's locales: `ar_BH` falls back to
 * `ar`, and `pt` picks `pt_BR` when only that variant is offered.
 */
final class LocaleResolver
{
    public const SESSION_KEY = 'alfakit.locale';

    public const COOKIE = 'alfakit_locale';

    public function resolve(Panel $panel, Request $request): Locale
    {
        $locales = $panel->getLocales();

        foreach ($this->candidates($panel, $request) as $candidate) {
            if (is_string($candidate) && $candidate !== '' && ($match = $this->match($candidate, $locales)) !== null) {
                return $match;
            }
        }

        return $panel->getDefaultLocale();
    }

    /**
     * @param  array<string, Locale>  $locales
     */
    public function match(string $candidate, array $locales): ?Locale
    {
        $code = Locale::normalize($candidate);

        if (isset($locales[$code])) {
            return $locales[$code];
        }

        $language = explode('_', $code)[0];

        if (isset($locales[$language])) {
            return $locales[$language];
        }

        foreach ($locales as $locale) {
            if ($locale->getLanguage() === $language) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * @return iterable<mixed>
     */
    private function candidates(Panel $panel, Request $request): iterable
    {
        if (($resolver = $panel->getResolveLocaleUsing()) !== null) {
            yield $resolver($request, array_keys($panel->getLocales()));
        }

        if ($request->hasSession()) {
            yield $request->session()->get(self::SESSION_KEY);
        }

        $user = $panel->auth()->user();

        if ($user instanceof HasLocalePreference) {
            yield $user->preferredLocale();
        }

        yield $request->cookie(self::COOKIE);

        if ($panel->detectsBrowserLocale()) {
            yield from $request->getLanguages();
        }
    }
}
