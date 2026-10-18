---
name: alfakit-localization
description: Make AlfaKit panels multilingual and global — panel locales and the language menu, user language preference, RTL, translating labels and model names, adding languages, plural strings, currencies and money fields, numbers, dates and timezones. Use for any translation, RTL, currency or timezone work.
---

# AlfaKit localization

## When to use this skill

Use it when adding languages, fixing a translation, supporting Arabic, Hebrew or other RTL languages, showing or entering money, or handling user timezones.

## Languages

```php
$panel
    ->locales(['en', 'ar', 'fr', 'pt_BR'])   // language menu appears automatically
    ->defaultLocale('en')
    ->languageSwitcher('topbar')            // 'user-menu' (default), 'topbar', 'both', 'hidden'
    ->persistLocaleUsing(fn (User $user, string $locale) => $user->update(['locale' => $locale]));
```

- `languageSwitcher()` (also `AlfaSix\Alfakit\Support\Enums\LanguageSwitcherPosition::Topbar`) puts the language menu in the user menu, a top bar languages button next to the theme switcher, both, or nowhere in the panel. Auth pages always keep their button; `localeSwitcher(false)` turns every menu off. Languages are listed by their own name (`Locale::make('ja')->getLabel()` is `日本語`). The frontend reads `localization.switcherPosition`; renderers place the button with `showsLanguageSwitcher(localization, 'topbar' | 'user-menu' | 'auth')` from `@alfakit/core`, as `data-slot="language-switcher"`, replaceable through the `panel:language-switcher` registry key. On phones (below `sm`) the top bar button of `'topbar'` / `'both'` moves into the user menu (`collapsesLanguageSwitcher(localization, hasUserMenu)`; the button is wrapped in `data-slot="topbar-language"`), unless there is no user menu (guests, `userMenu(false)`).

- Built-in strings in 12 languages: `composer require alfasix/alfakit-translations`.
- Read the user's language by implementing `Illuminate\Contracts\Translation\HasLocalePreference` on the user model.
- The order is: switcher choice in this session, then the user preference, cookie, `Accept-Language`, and finally the default.
- Any other language: `php artisan alfakit:lang:add sw`, translate `lang/vendor/alfakit/sw/*.php`, then add `'sw'` to `locales()`.
- Check completeness: `php artisan alfakit:lang:check -v`. It fails on missing strings or broken `:placeholders`, so it fits CI.
- Override single strings in `lang/vendor/alfakit/{locale}/{group}.php`.

## Your labels and model names

- Wrap labels in `__()`. Or call `->translateLabel()` (globally: `Field::configureUsing(fn (Field $f) => $f->translateLabel())`) to look up generated labels in `lang/{locale}.json`.
- Model names: `lang/{locale}/models.php` with `'category' => ['singular' => 'تصنيف', 'plural' => 'تصنيفات']` or `'category' => 'Kategorie|Kategorien'`. Navigation, titles, buttons and breadcrumbs follow. URLs stay English (`/categories`).
- Frontend strings for custom components: `$panel->frontendTranslations(['billing' => 'billing'])`, then `t('billing.key')` and `choice('billing.items', $count)` (Laravel `trans_choice` syntax with CLDR plural rules).

## Translatable content

`TextInput::make('title')->required()->translatable()` (also `Textarea`, `RichEditor`, `MarkdownEditor`) edits one value per panel locale (or `->translatable(locales: ['en', 'ar'])`) with a language switcher; the state is `['en' => '…', 'ar' => '…']`. Storage: array/JSON casts get the array, an uncast text column a JSON string, and spatie/laravel-translatable `HasTranslations` attributes are loaded with `getTranslations()` and saved as an array (spatie is optional). `required()` applies to the default locale or `->requiredLocales(['en', 'ar'])`; other rules apply to every language and errors are keyed `title.ar`. `LocaleSwitcher::make()` (`AlfaSix\Alfakit\Actions\LocaleSwitcher`) in a page's `getHeaderActions()` lets the user pick the language every translatable field opens on; it is kept in the session per panel (`TranslatableLocale::get()` / `::set()`).

## RTL

RTL is automatic for RTL languages, including any script subtag such as `pa_Arab`. The layout, sidebar, icons, charts and inputs all mirror. Custom components must use logical Tailwind classes (`ms`, `ps`, `start`, `end`, `text-start`, `border-s`) and `rtl:rotate-180` on arrows. Bundle a font that covers the script, such as Noto Sans Arabic or Vazirmatn.

## Money

```php
$panel->currency('EUR');                                          // default for the panel
TextColumn::make('total')->money(fn (Order $r) => $r->currency, inMinorUnits: true);
TextEntry::make('price')->money('BHD');
Select::make('currency')->currencies()->searchable()->live();
MoneyInput::make('total')->currency(fn (Get $get) => $get('currency'))->minorUnits()->minValue(0);
```

- Store money as integer minor units. The divisor follows the currency (USD 100, BHD 1000, JPY 1), and `Currency::decimals('BHD')` returns 3.
- `MoneyInput` accepts the locale's separators and digits and validates `decimal:0,{digits}`.
- PHP helpers: `Currency::format()`, `toMinorUnits()`, `fromMinorUnits()`, `name()`, `symbol()` and `options()`.

## Dates and timezones

- `$panel->timezone(fn () => auth()->user()?->timezone)`. Storage stays in `app.timezone`, while display and `DateTimePicker` input use the user's timezone. Date-only fields are never shifted.
- Columns: `->date()`, `->dateTime()` and `->since()` follow the user's language. Pickers start the week on the locale's first day.
- Option lists: `->timezones()`, `->languages()` and `->panelLocales()` on selects.

## Pitfalls

- Never concatenate translated fragments. Use one key with `:placeholders`.
- Never format money with `number_format()` or JavaScript `toFixed()` in views. Use `money()`, `Currency::format()` or `useFormatter()`.
- Don't derive URLs or relationship names from translated labels.
