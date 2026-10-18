<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Support\Number;
use InvalidArgumentException;
use NumberFormatter;
use ResourceBundle;
use Throwable;

/**
 * ISO 4217 currency data backed by ICU (ext-intl), so every currency and locale works
 * without a hand-maintained table: BHD has 3 decimals, JPY none, names and symbols are
 * localised, and only currencies still in circulation are offered in pickers.
 *
 * ```php
 * Currency::decimals('BHD');                  // 3
 * Currency::format(12345, 'BHD', 'ar_BH', inMinorUnits: true); // "١٢٫٣٤٥ د.ب.‏"
 * Currency::toMinorUnits('12.5', 'JPY');      // 13
 * Currency::options(locale: 'fr');            // ['EUR' => 'euro (EUR)', …]
 * ```
 */
final class Currency
{
    /** @var array<string, int> */
    private static array $decimals = [];

    /** @var array<int, string>|null */
    private static ?array $active = null;

    /**
     * The currency used when none is given: the current panel's, otherwise Laravel's
     * `Number::defaultCurrency()` (USD unless the app changed it).
     */
    public static function default(): string
    {
        return Number::defaultCurrency();
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException("[{$code}] is not an ISO 4217 currency code.");
        }

        return $code;
    }

    /** Number of minor-unit digits (2 for USD, 3 for BHD, 0 for JPY). */
    public static function decimals(string $code): int
    {
        $code = self::normalize($code);

        if (isset(self::$decimals[$code])) {
            return self::$decimals[$code];
        }

        try {
            $formatter = new NumberFormatter("en@currency={$code}", NumberFormatter::CURRENCY);
            $digits = $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);
        } catch (Throwable) {
            $digits = false;
        }

        return self::$decimals[$code] = is_int($digits) ? $digits : 2;
    }

    /** Localised name ("Bahraini Dinar", "dinar bahreïni"). */
    public static function name(string $code, ?string $locale = null): string
    {
        $code = self::normalize($code);

        try {
            $names = ResourceBundle::create($locale ?? app()->getLocale(), 'ICUDATA-curr')?->get('Currencies');
            $entry = $names instanceof ResourceBundle ? $names->get($code) : null;
            $name = $entry instanceof ResourceBundle ? $entry->get(1) : null;
        } catch (Throwable) {
            $name = null;
        }

        return is_string($name) && $name !== '' ? $name : $code;
    }

    /** Localised symbol ("$", "€", "د.ب.‏"; "BHD" where the locale has no symbol). */
    public static function symbol(string $code, ?string $locale = null): string
    {
        $code = self::normalize($code);

        try {
            $formatter = new NumberFormatter(($locale ?? app()->getLocale())."@currency={$code}", NumberFormatter::CURRENCY);
            $symbol = $formatter->getSymbol(NumberFormatter::CURRENCY_SYMBOL);
        } catch (Throwable) {
            $symbol = false;
        }

        return is_string($symbol) && $symbol !== '' ? $symbol : $code;
    }

    /**
     * Codes of currencies currently in circulation (legal tender in at least one region).
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        if (self::$active !== null) {
            return self::$active;
        }

        $active = [];

        try {
            $map = ResourceBundle::create('supplementalData', 'ICUDATA-curr', false)?->get('CurrencyMap');

            foreach ($map instanceof ResourceBundle ? $map : [] as $regionCurrencies) {
                foreach ($regionCurrencies as $entry) {
                    if ($entry->get('to') === null && $entry->get('tender') !== 'false') {
                        $active[(string) $entry->get('id')] = true;
                    }
                }
            }
        } catch (Throwable) {
            // Fall through to the names list below.
        }

        if ($active === []) {
            try {
                foreach (ResourceBundle::create('en', 'ICUDATA-curr')?->get('Currencies') ?? [] as $code => $_) {
                    $active[(string) $code] = true;
                }
            } catch (Throwable) {
                $active = ['EUR' => true, 'GBP' => true, 'USD' => true];
            }
        }

        $codes = array_keys($active);
        sort($codes);

        return self::$active = $codes;
    }

    /**
     * Options for a Select: `['BHD' => 'Bahraini Dinar (BHD)', …]`, sorted by name.
     *
     * @param  array<int, string>|null  $only  restrict to these codes, in this order
     * @return array<string, string>
     */
    public static function options(?array $only = null, ?string $locale = null): array
    {
        $codes = $only === null ? self::codes() : array_map(self::normalize(...), $only);
        $options = [];

        foreach ($codes as $code) {
            $options[$code] = self::name($code, $locale)." ({$code})";
        }

        if ($only === null) {
            asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return $options;
    }

    /**
     * Format an amount for display in the current (or given) locale.
     */
    public static function format(int|float|string|null $amount, ?string $currency = null, ?string $locale = null, bool $inMinorUnits = false): string
    {
        $currency = self::normalize($currency ?? self::default());
        $value = $inMinorUnits ? self::fromMinorUnits((int) $amount, $currency) : (string) ($amount ?? 0);

        $formatted = NumberFormat::currency((float) $value, $currency, $locale ?? app()->getLocale(), self::decimals($currency));

        return $formatted === false ? $value.' '.$currency : $formatted;
    }

    /**
     * `12.345` BHD → `12345`. Rounds half away from zero to the currency's precision.
     */
    public static function toMinorUnits(int|float|string $amount, string $currency): int
    {
        $decimals = self::decimals($currency);

        return (int) round(((float) $amount) * (10 ** $decimals), 0, PHP_ROUND_HALF_UP);
    }

    /**
     * `12345` BHD → `"12.345"`, computed on integers so no precision is lost.
     */
    public static function fromMinorUnits(int $amount, string $currency): string
    {
        $decimals = self::decimals($currency);

        if ($decimals === 0) {
            return (string) $amount;
        }

        $sign = $amount < 0 ? '-' : '';
        $absolute = (string) abs($amount);
        $absolute = str_pad($absolute, $decimals + 1, '0', STR_PAD_LEFT);

        return $sign.substr($absolute, 0, -$decimals).'.'.substr($absolute, -$decimals);
    }
}
