<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Support\Number;
use MessageFormatter;
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
    /** ICU units of `fileSize()`, by power of 1024. */
    private const SIZE_UNITS = ['digital-byte', 'digital-kilobyte', 'digital-megabyte', 'digital-gigabyte', 'digital-terabyte'];

    /** @var array<string, NumberFormatter|false> */
    private static array $formatters = [];

    /** @var array<string, MessageFormatter> */
    private static array $messages = [];

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

    /**
     * A file size in the locale, like the renderers' `fileManagerSize()`: 1024-based units,
     * bytes spelled out and pluralised ("11 bytes", "11 octets"), larger units short
     * ("1.5 kB", "1,5 ko") with at most `$precision` decimals.
     */
    public static function fileSize(int|float $bytes, int $precision = 1, ?string $locale = null): string
    {
        $locale ??= Number::defaultLocale();
        $value = is_finite((float) $bytes) && $bytes > 0 ? (float) $bytes : 0.0;
        $unit = 0;

        while ($value >= 1024 && $unit < count(self::SIZE_UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        $skeleton = $unit === 0
            ? '::measure-unit/'.self::SIZE_UNITS[0].' unit-width-full-name precision-integer'
            : '::measure-unit/'.self::SIZE_UNITS[$unit].' unit-width-short '.($precision > 0 ? '.'.str_repeat('#', $precision) : 'precision-integer');

        $key = 'size|'.$locale.'|'.$skeleton;

        try {
            $formatter = self::$messages[$key] ??= new MessageFormatter($locale, '{0, number, '.$skeleton.'}');
            $formatted = $formatter->format([$unit === 0 ? (int) round($value) : $value]);
        } catch (Throwable) {
            $formatted = false;
        }

        return is_string($formatted) && $formatted !== '' ? $formatted : Number::fileSize($bytes, $precision);
    }

    /** Drop cached formatters (tests, long-running workers changing ICU data). */
    public static function flush(): void
    {
        self::$formatters = [];
        self::$messages = [];
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
