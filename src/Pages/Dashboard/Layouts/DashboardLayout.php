<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Dashboard\Layouts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A user's own layout of a customizable dashboard (one per panel, dashboard, user and
 * tenant). `layout` is a list of `{id, span, hidden}` entries in display order.
 *
 * @property int $id
 * @property string $panel
 * @property string $dashboard
 * @property string $user_type
 * @property string $user_id
 * @property string $tenant_key
 * @property array<int, array{id: string, span?: int|string|null, hidden?: bool}> $layout
 */
class DashboardLayout extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return (string) config('alfakit.dashboards.layouts_table', 'alfakit_dashboard_layouts');
    }

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeFor(string $panel, string $dashboard, Authenticatable $user, ?string $tenantKey): array
    {
        return [
            'panel' => $panel,
            'dashboard' => $dashboard,
            'user_type' => $user instanceof Model ? $user->getMorphClass() : $user::class,
            'user_id' => (string) $user->getAuthIdentifier(),
            'tenant_key' => $tenantKey ?? '',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public static function findLayout(string $panel, string $dashboard, Authenticatable $user, ?string $tenantKey): ?array
    {
        $row = static::query()->where(static::scopeFor($panel, $dashboard, $user, $tenantKey))->first();

        if ($row === null) {
            return null;
        }

        $layout = $row->layout;

        return is_array($layout) ? array_values(array_filter($layout, is_array(...))) : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $layout
     */
    public static function store(string $panel, string $dashboard, Authenticatable $user, ?string $tenantKey, array $layout): void
    {
        static::query()->updateOrCreate(static::scopeFor($panel, $dashboard, $user, $tenantKey), ['layout' => $layout]);
    }

    public static function forget(string $panel, string $dashboard, Authenticatable $user, ?string $tenantKey): void
    {
        static::query()->where(static::scopeFor($panel, $dashboard, $user, $tenantKey))->delete();
    }
}
