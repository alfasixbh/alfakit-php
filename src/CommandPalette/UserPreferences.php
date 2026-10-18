<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\CommandPalette;

use AlfaSix\Alfakit\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Small per-user, per-panel preferences (customised keyboard shortcuts). Stored in the
 * `alfakit_user_preferences` table when it exists (publish its migration), else in the
 * cache, so the feature works without a migration.
 */
final class UserPreferences
{
    public const TABLE = 'alfakit_user_preferences';

    private static ?bool $hasTable = null;

    public static function get(Panel $panel, Authenticatable $user, string $key): mixed
    {
        if (self::usesTable()) {
            $value = DB::table(self::TABLE)
                ->where(self::identity($panel, $user))
                ->where('key', $key)
                ->value('value');

            return is_string($value) ? json_decode($value, true) : null;
        }

        return Cache::get(self::cacheKey($panel, $user, $key));
    }

    public static function put(Panel $panel, Authenticatable $user, string $key, mixed $value): void
    {
        if ($value === null) {
            self::forget($panel, $user, $key);

            return;
        }

        if (self::usesTable()) {
            $now = now();

            DB::table(self::TABLE)->upsert(
                [[...self::identity($panel, $user), 'key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]],
                ['user_type', 'user_id', 'panel', 'key'],
                ['value', 'updated_at'],
            );

            return;
        }

        Cache::forever(self::cacheKey($panel, $user, $key), $value);
    }

    public static function forget(Panel $panel, Authenticatable $user, string $key): void
    {
        if (self::usesTable()) {
            DB::table(self::TABLE)->where(self::identity($panel, $user))->where('key', $key)->delete();

            return;
        }

        Cache::forget(self::cacheKey($panel, $user, $key));
    }

    /** Forget whether the table exists (tests that create it at runtime). */
    public static function flush(): void
    {
        self::$hasTable = null;
    }

    private static function usesTable(): bool
    {
        if (self::$hasTable !== null) {
            return self::$hasTable;
        }

        try {
            return self::$hasTable = Schema::hasTable(self::TABLE);
        } catch (Throwable) {
            return self::$hasTable = false;
        }
    }

    /**
     * @return array{user_type: string, user_id: string, panel: string}
     */
    private static function identity(Panel $panel, Authenticatable $user): array
    {
        return [
            'user_type' => $user instanceof Model ? $user->getMorphClass() : $user::class,
            'user_id' => (string) $user->getAuthIdentifier(),
            'panel' => $panel->getId(),
        ];
    }

    private static function cacheKey(Panel $panel, Authenticatable $user, string $key): string
    {
        return 'alfakit:preferences:'.sha1(implode('|', [...array_values(self::identity($panel, $user)), $key]));
    }
}
