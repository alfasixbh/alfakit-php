<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Carbon\CarbonInterface;

/**
 * When the temporary (signed) URLs of private files expire. Like Filament, the expiry is
 * `config('alfakit.temporary_file_url_expiry_minutes')` from now, rounded up to the end of
 * the hour, so the same URL is generated for a while and browsers can cache the file.
 */
final class TemporaryUrls
{
    public const DEFAULT_EXPIRY_MINUTES = 30;

    public static function defaultExpiryMinutes(): int
    {
        $minutes = config('alfakit.temporary_file_url_expiry_minutes', self::DEFAULT_EXPIRY_MINUTES);

        return is_numeric($minutes) && (int) $minutes > 0 ? (int) $minutes : self::DEFAULT_EXPIRY_MINUTES;
    }

    /**
     * @param  int|null  $minutes  Null uses the configured default.
     */
    public static function expiresAt(?int $minutes = null): CarbonInterface
    {
        $minutes = $minutes !== null && $minutes > 0 ? $minutes : self::defaultExpiryMinutes();

        return now()->addMinutes($minutes)->endOfHour();
    }
}
