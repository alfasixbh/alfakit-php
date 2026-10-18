<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Support\Facades\Log;

/**
 * Runtime deprecation warnings. Each subject is reported once per process through the
 * application's logger, and collected so a debug panel can list what the current process
 * still relies on:
 *
 * ```php
 * Deprecations::warn('Table::actions()', 'use recordActions() instead', since: '1.2');
 * ```
 *
 * `Deprecations::toDebug()` is the list meant for the `deprecations` key of the debug prop:
 * it is non-null only when `app.debug` is on.
 */
final class Deprecations
{
    /** @var array<string, array{subject: string, message: string, since: string|null}> */
    private static array $collected = [];

    /**
     * Report that `$subject` (a method, option, command alias…) is deprecated. The same
     * subject is logged only once per process; later calls are ignored.
     */
    public static function warn(string $subject, string $message, ?string $since = null): void
    {
        if (isset(self::$collected[$subject])) {
            return;
        }

        self::$collected[$subject] = ['subject' => $subject, 'message' => $message, 'since' => $since];

        Log::warning(self::format($subject, $message, $since), ['alfakit' => 'deprecation', 'subject' => $subject, 'since' => $since]);
    }

    /**
     * Every deprecation reported in this process, in report order.
     *
     * @return list<array{subject: string, message: string, since: string|null}>
     */
    public static function all(): array
    {
        return array_values(self::$collected);
    }

    public static function has(string $subject): bool
    {
        return isset(self::$collected[$subject]);
    }

    /**
     * The `deprecations` list for the debug prop: the collected warnings when `app.debug` is
     * on, null otherwise (so the key can be compacted away in production).
     *
     * @return list<array{subject: string, message: string, since: string|null}>|null
     */
    public static function toDebug(): ?array
    {
        return config('app.debug') ? self::all() : null;
    }

    /** Forget every reported deprecation (tests, long-running workers between jobs). */
    public static function flush(): void
    {
        self::$collected = [];
    }

    public static function format(string $subject, string $message, ?string $since = null): string
    {
        $when = $since === null ? '' : " since Alfakit {$since}";

        return "[Alfakit] {$subject} is deprecated{$when}: {$message}";
    }
}
