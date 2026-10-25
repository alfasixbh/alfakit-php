<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization\Concerns;

use AlfaSix\Alfakit\Localization\Locale;
use AlfaSix\Alfakit\Support\Currency;
use AlfaSix\Alfakit\Support\Enums\LanguageSwitcherPosition;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;

/**
 * Languages, currency and timezone of a panel.
 *
 * ```php
 * $panel
 *     ->locales(['en', 'ar', 'fr', 'pt_BR'])
 *     ->defaultLocale('en')
 *     ->languageSwitcher('topbar')
 *     ->persistLocaleUsing(fn (User $user, string $locale) => $user->update(['locale' => $locale]))
 *     ->currency(fn () => auth()->user()?->team?->currency ?? 'USD')
 *     ->timezone(fn () => auth()->user()?->timezone);
 * ```
 */
trait HasLocalization
{
    /** @var array<int|string, string|Locale>|Closure */
    protected array|Closure $locales = [];

    protected string|Closure|null $defaultLocale = null;

    protected bool|Closure|null $hasLocaleSwitcher = null;

    protected LanguageSwitcherPosition|Closure $languageSwitcherPosition = LanguageSwitcherPosition::UserMenu;

    protected bool|Closure $detectsBrowserLocale = true;

    protected ?Closure $persistLocaleUsing = null;

    protected ?Closure $resolveLocaleUsing = null;

    protected string|Closure|null $currency = null;

    protected string|Closure|null $displayTimezone = null;

    /** @var array<string, string> */
    protected array $frontendTranslations = [];

    /** @var array<string, Locale>|null */
    protected ?array $cachedLocales = null;

    /**
     * Languages users can pick. Accepts codes (`'pt_BR'`, `'pt-BR'`), {@see Locale}
     * instances, or `code => label` pairs. Defaults to the app locale only.
     *
     * @param  array<int|string, string|Locale>|Closure  $locales
     */
    public function locales(array|Closure $locales): static
    {
        $this->locales = $locales;
        $this->cachedLocales = null;

        return $this;
    }

    /**
     * @return array<string, Locale> keyed by normalised code
     */
    public function getLocales(): array
    {
        // Closures may depend on the request (tenant, user), so only static lists are cached.
        if ($this->cachedLocales !== null && ! $this->locales instanceof Closure) {
            return $this->cachedLocales;
        }

        $locales = [];

        foreach ((array) $this->evaluate($this->locales) as $key => $value) {
            $locale = match (true) {
                $value instanceof Locale => $value,
                is_string($key) => Locale::make($key)->label($value),
                default => Locale::make((string) $value),
            };

            $locales[$locale->getCode()] = $locale;
        }

        if ($locales === []) {
            $fallback = Locale::make((string) ($this->evaluate($this->defaultLocale) ?? config('app.locale', 'en')));
            $locales[$fallback->getCode()] = $fallback;
        }

        return $this->cachedLocales = $locales;
    }

    public function getLocale(string $code): ?Locale
    {
        return $this->getLocales()[Locale::normalize($code)] ?? null;
    }

    public function defaultLocale(string|Closure|null $locale): static
    {
        $this->defaultLocale = $locale;

        return $this;
    }

    public function getDefaultLocale(): Locale
    {
        $locales = $this->getLocales();
        $preferred = $this->evaluate($this->defaultLocale) ?? config('app.locale', 'en');

        return $locales[Locale::normalize((string) $preferred)] ?? Arr::first($locales);
    }

    /**
     * Show a language menu (user menu and auth pages). On by default when the panel
     * has more than one locale.
     */
    public function localeSwitcher(bool|Closure $condition = true): static
    {
        $this->hasLocaleSwitcher = $condition;

        return $this;
    }

    public function hasLocaleSwitcher(): bool
    {
        return (bool) ($this->evaluate($this->hasLocaleSwitcher) ?? count($this->getLocales()) > 1);
    }

    /**
     * Where the language menu sits: `'user-menu'` (a submenu, the default), `'topbar'`
     * (a languages button next to the theme switcher), `'both'` or `'hidden'`. Auth pages
     * keep their switcher; `localeSwitcher(false)` turns every menu off.
     */
    public function languageSwitcher(LanguageSwitcherPosition|string|Closure $position = LanguageSwitcherPosition::UserMenu): static
    {
        $this->languageSwitcherPosition = is_string($position) ? LanguageSwitcherPosition::from($position) : $position;

        return $this;
    }

    public function getLanguageSwitcherPosition(): LanguageSwitcherPosition
    {
        $position = $this->evaluate($this->languageSwitcherPosition);

        return $position instanceof LanguageSwitcherPosition ? $position : LanguageSwitcherPosition::from((string) $position);
    }

    /** Use the browser's `Accept-Language` header for visitors who have not chosen yet. */
    public function detectBrowserLocale(bool|Closure $condition = true): static
    {
        $this->detectsBrowserLocale = $condition;

        return $this;
    }

    public function detectsBrowserLocale(): bool
    {
        return (bool) $this->evaluate($this->detectsBrowserLocale);
    }

    /**
     * Store a signed-in user's choice, e.g. on a `locale` column. Read it back by
     * implementing Laravel's `HasLocalePreference` on the user model.
     */
    public function persistLocaleUsing(?Closure $callback): static
    {
        $this->persistLocaleUsing = $callback;

        return $this;
    }

    public function getPersistLocaleUsing(): ?Closure
    {
        return $this->persistLocaleUsing;
    }

    /**
     * Replace the resolution order. Receives the request and the allowed locale codes;
     * return a code, or null to fall back to the default resolution.
     */
    public function resolveLocaleUsing(?Closure $callback): static
    {
        $this->resolveLocaleUsing = $callback;

        return $this;
    }

    public function getResolveLocaleUsing(): ?Closure
    {
        return $this->resolveLocaleUsing;
    }

    /** Default ISO 4217 currency for money columns, entries and inputs. */
    public function currency(string|Closure|null $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getCurrency(): string
    {
        // Blank counts as unset: `ALFAKIT_CURRENCY=` in .env is an empty string, not null.
        $currency = $this->evaluate($this->currency);

        if (blank($currency)) {
            $currency = config('alfakit.currency');
        }

        if (blank($currency)) {
            $currency = Number::defaultCurrency();
        }

        return Currency::normalize((string) $currency);
    }

    /**
     * Timezone dates are displayed and entered in (per user, if you like). Storage stays
     * in the app timezone.
     */
    public function timezone(string|Closure|null $timezone): static
    {
        $this->displayTimezone = $timezone;

        return $this;
    }

    public function getTimezone(): string
    {
        return (string) ($this->evaluate($this->displayTimezone) ?? config('app.timezone', 'UTC'));
    }

    /**
     * Extra translation groups shared with the frontend, available to custom
     * components through `t('key.path')`. `['billing' => 'billing::ui']` exposes the
     * `billing::ui` group under `billing.`; a list entry uses the group name as key.
     *
     * @param  array<int|string, string>  $groups
     */
    public function frontendTranslations(array $groups): static
    {
        foreach ($groups as $key => $group) {
            $this->frontendTranslations[is_string($key) ? $key : str_replace(['::', '.'], '_', $group)] = $group;
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFrontendTranslations(): array
    {
        $translations = (array) trans('alfakit::ui');

        foreach ($this->frontendTranslations as $key => $group) {
            $lines = trans($group);
            $translations[$key] = is_array($lines) ? $lines : [];
        }

        return $translations;
    }

    public function getCurrentLocale(): Locale
    {
        return $this->getLocale(app()->getLocale()) ?? Locale::make(app()->getLocale());
    }

    /**
     * @return array<string, mixed>
     */
    public function getLocalizationSchema(): array
    {
        $current = $this->getCurrentLocale();
        $switcher = $this->hasLocaleSwitcher();
        $position = $this->getLanguageSwitcherPosition();

        // Sidebar layouts without a top bar (`topbar(false)`) keep the button on phones' slim
        // bar only, so desktop users also get the user menu entry.
        if ($position === LanguageSwitcherPosition::Topbar && ! $this->hasTopbar() && ! $this->hasTopNavigation) {
            $position = LanguageSwitcherPosition::Both;
        }

        return [
            ...$current->toSchema(),
            'fallbackLocale' => (string) config('app.fallback_locale', 'en'),
            'firstDayOfWeek' => $current->getFirstDayOfWeek(),
            'currency' => $this->getCurrency(),
            'timezone' => $this->getTimezone(),
            'switcher' => $switcher,
            'switcherPosition' => $position->value,
            'locales' => $switcher ? array_values(array_map(static fn (Locale $locale): array => $locale->toSchema(), $this->getLocales())) : [],
        ];
    }
}
