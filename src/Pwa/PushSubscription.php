<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pwa;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A browser that turned Web Push on for a panel (`Panel::pwa(push: true)`).
 *
 * @property int $id
 * @property string $panel
 * @property string $user_type
 * @property string $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string|null $user_agent
 */
class PushSubscription extends Model
{
    protected $guarded = [];

    private static ?bool $tableExists = null;

    public function getTable(): string
    {
        return (string) config('alfakit.pwa.subscriptions_table', 'alfakit_push_subscriptions');
    }

    /** Whether the subscriptions table was migrated (publish `alfakit-pwa-migrations`). */
    public static function isAvailable(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            return self::$tableExists = Schema::hasTable((new static)->getTable());
        } catch (Throwable) {
            return self::$tableExists = false;
        }
    }

    public static function flushAvailability(): void
    {
        self::$tableExists = null;
    }

    /**
     * @return array{user_type: string, user_id: string}
     */
    public static function owner(Authenticatable $user): array
    {
        return [
            'user_type' => $user instanceof Model ? $user->getMorphClass() : $user::class,
            'user_id' => (string) $user->getAuthIdentifier(),
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOwnedBy(Builder $query, Authenticatable $user): Builder
    {
        return $query->where(static::owner($user));
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
