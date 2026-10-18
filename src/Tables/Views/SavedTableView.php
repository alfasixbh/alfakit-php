<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Views;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * A view a user saved on a table (`Table::savedViews()`): their own, or shared with
 * everyone on the same panel, table and tenant. `state` is the table state the view
 * restores; it is sanitised against the table's columns and filters whenever it is read.
 *
 * @property int $id
 * @property string $panel
 * @property string $table_key
 * @property string $tenant_key
 * @property string $user_type
 * @property string $user_id
 * @property string $slug
 * @property string $name
 * @property string|null $icon
 * @property bool $is_shared
 * @property array<string, mixed> $state
 */
class SavedTableView extends Model
{
    protected $guarded = [];

    private static ?bool $tableExists = null;

    public function getTable(): string
    {
        return (string) config('alfakit.tables.views_table', 'alfakit_table_views');
    }

    protected function casts(): array
    {
        return ['state' => 'array', 'is_shared' => 'boolean'];
    }

    /**
     * Whether the views table was migrated (publish `alfakit-table-views-migrations`).
     * Without it, tables still show the views defined in code.
     */
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

    /** Forget whether the table exists (tests that create it at runtime). */
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
     * The views a user sees on a table: their own and the shared ones, oldest first.
     *
     * @return Builder<static>
     */
    public static function visibleTo(string $panel, string $tableKey, ?string $tenantKey, Authenticatable $user): Builder
    {
        $owner = static::owner($user);

        return static::query()
            ->where(['panel' => $panel, 'table_key' => $tableKey, 'tenant_key' => $tenantKey ?? ''])
            ->where(static fn (Builder $query) => $query->where('is_shared', true)->orWhere(static fn (Builder $query) => $query->where($owner)))
            ->orderBy('is_shared', 'desc')
            ->orderBy('id');
    }

    public function isOwnedBy(Authenticatable $user): bool
    {
        $owner = static::owner($user);

        return $this->user_type === $owner['user_type'] && $this->user_id === $owner['user_id'];
    }
}
