<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\AuthKit\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The signed-in user's browser sessions, read from the `database` session driver's table.
 */
final class BrowserSessions
{
    public static function isAvailable(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * The ten most recent, newest first.
     *
     * @return array<int, array{id: string, device: string, ip_address: ?string, last_active: string, is_current: bool}>
     */
    public static function for(Authenticatable $user): array
    {
        if (! self::isAvailable()) {
            return [];
        }

        $current = request()->hasSession() ? request()->session()->getId() : null;

        return self::table()
            ->where('user_id', $user->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->limit(10)
            ->get()
            ->map(static fn (stdClass $session): array => [
                'id' => (string) $session->id,
                'device' => self::describe(isset($session->user_agent) ? (string) $session->user_agent : ''),
                'ip_address' => isset($session->ip_address) ? (string) $session->ip_address : null,
                'last_active' => (string) $session->id === $current
                    ? __('alfakit::auth.account.sessions.this_device')
                    : Carbon::createFromTimestamp((int) $session->last_activity)->locale(app()->getLocale())->diffForHumans(),
                'is_current' => (string) $session->id === $current,
            ])
            ->values()
            ->all();
    }

    /**
     * End every other session of the user. Returns how many were ended.
     */
    public static function endOthers(Authenticatable $user): int
    {
        if (! self::isAvailable()) {
            return 0;
        }

        $current = request()->hasSession() ? request()->session()->getId() : '';

        return self::table()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $current)
            ->delete();
    }

    /**
     * "Chrome on macOS" from a user agent string.
     */
    public static function describe(string $userAgent): string
    {
        if ($userAgent === '') {
            return __('alfakit::users.sessions.unknown_device');
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser !== null && $platform !== null) {
            return __('alfakit::auth.sessions.device', ['browser' => $browser, 'platform' => $platform]);
        }

        return $browser ?? $platform ?? __('alfakit::users.sessions.unknown_device');
    }

    private static function table(): Builder
    {
        return DB::connection(config('session.connection'))->table((string) config('session.table', 'sessions'));
    }
}
