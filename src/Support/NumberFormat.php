<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Support\Number;
use NumberFormatter;
use Throwable;

/**
 * `Number::format()` / `Number::currency()` with the ICU formatters reused: building a
 * `NumberFormatter` costs far more than formatting with one, and a 500-row table with a
 * few numeric columns formats thousands of values per request. Output is identical to
 * Laravel's helpers (same locale default, precision attributes and negative-zero rule).
 */
final class NumberFormat
{
    /** @var array<string, NumberFormatter|false> */
    private static array $formatters = [];

    public static function format(int|float $number, ?int $precision = null, ?int $maxPrecision = null, ?string $locale = null): string|false
    {
        $locale ??= Number::defaultLocale();
        $formatter = self::formatter($locale, NumberFormatter::DECIMAL, $precision, $maxPrecision);

        if ($formatter === false) {
            return Number::format($number, $precision, $maxPrecision, $locale);
        }

        return $formatter->format(self::withoutNegativeZero($number, $formatter->format(...)));
    }

    public static function currency(int|float $number, string $in = '', ?string $locale = null, ?int $precision = null): string|false
    {
        $locale ??= Number::defaultLocale();
        $currency = $in !== '' ? $in : Number::defaultCurrency();
        $formatter = self::formatter($locale, NumberFormatter::CURRENCY, $precision, null);

        if ($formatter === false) {
            return Number::currency($number, $currency, $locale, $precision);
        }

        return $formatter->formatCurrency(
            self::withoutNegativeZero($number, static fn (int|float $value): string => (string) $formatter->formatCurrency($value, $currency)),
            $currency,
        );
    }

    /** Drop cached formatters (tests, long-running workers changing ICU data). */
    public static function flush(): void
    {
        self::$formatters = [];
    }

    private static function formatter(string $locale, int $style, ?int $precision, ?int $maxPrecision): NumberFormatter|false
    {
        $key = $locale.'|'.$style.'|'.($precision ?? '').'|'.($maxPrecision ?? '');

        if (isset(self::$formatters[$key])) {
            return self::$formatters[$key];
        }

        // Bounded: locales × styles × precisions in use is small, but never let a loop of
        // arbitrary precisions grow the cache without limit.
        if (count(self::$formatters) > 256) {
            self::$formatters = [];
        }

        try {
            $formatter = new NumberFormatter($locale, $style);
        } catch (Throwable) {
            return self::$formatters[$key] = false;
        }

        if ($maxPrecision !== null) {
            $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxPrecision);
        } elseif ($precision !== null) {
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $precision);
        }

        return self::$formatters[$key] = $formatter;
    }

    /**
     * @param  callable(int|float): (string|false)  $format
     */
    private static function withoutNegativeZero(int|float $number, callable $format): int|float
    {
        if ($number == 0) {
            return 0;
        }

        return $number < 0 && $format(abs($number)) === $format(0) ? 0 : $number;
    }
}
