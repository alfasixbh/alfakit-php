<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Contracts\HasTenants;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\Api\DataSourceException;
use AlfaSix\Alfakit\Resources\ApiResource;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\Authorization;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Broadcast;

/**
 * The broadcast channels of a panel and how they are authorised.
 *
 * - `private-alfakit.{panel}.{tenant}.{model}`: changes of a model's records (live tables
 *   and widgets). Authorised like the resource's list: the `viewAny` policy ability.
 * - `presence-alfakit.{panel}.{tenant}.{model}.{key}`: who is on a record page, and its
 *   changes (edit-conflict banners). Authorised like the record's pages: `view` or `update`
 *   on the record, found through the resource's tenant-scoped query.
 *
 * `{tenant}` is the current tenant's key on tenancy panels for tenant-owned models, `_`
 * otherwise; `{model}` is the model class with `-` for `\`. Payloads only carry keys:
 * clients refetch through the authorised endpoints.
 *
 * Records of API resources are all `ApiRecord`s, so their presence channels name the
 * resource class instead (`…{tenant}.App-Alfakit-Resources-SupplierResource.{key}`), carry
 * the tenant on every tenancy panel (the tenant is handed to the data source) and find
 * the record through the resource's data source.
 */
final class RealtimeChannels
{
    /** The `{tenant}` segment of models that don't belong to a tenant. */
    public const NO_TENANT = '_';

    /** Echo listens with `.listen('.alfakit.record', …)`. */
    public const RECORD_EVENT = 'alfakit.record';

    /** @var array<string, class-string<resource>|false> */
    private static array $resources = [];

    /** @var array<string, true> panel ids whose channels are registered */
    private static array $registered = [];

    public static function modelSegment(string $model): string
    {
        return str_replace('\\', '-', ltrim($model, '\\'));
    }

    /**
     * @return class-string<Model>|null
     */
    public static function modelFromSegment(string $segment): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $segment) !== 1) {
            return null;
        }

        $class = str_replace('-', '\\', $segment);

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    /** A key usable in a channel name (Pusher allows `A-Za-z0-9_-=@,;`), or null. */
    public static function keySegment(mixed $key): ?string
    {
        if (is_int($key)) {
            return (string) $key;
        }

        return is_string($key) && preg_match('/^[A-Za-z0-9_\-=@,;]{1,120}$/', $key) === 1 ? $key : null;
    }

    /**
     * The panel resource for a model (the first registered one), if any.
     *
     * @param  class-string<Model>  $model
     * @return class-string<resource>|null
     */
    public static function resourceFor(Panel $panel, string $model): ?string
    {
        $cacheKey = spl_object_id($panel).'|'.$model;

        if (! array_key_exists($cacheKey, self::$resources)) {
            self::$resources[$cacheKey] = false;

            foreach ($panel->getResources() as $resource) {
                if (ltrim($resource::getModel(), '\\') === $model) {
                    self::$resources[$cacheKey] = $resource;

                    break;
                }
            }
        }

        return self::$resources[$cacheKey] ?: null;
    }

    /**
     * Whether the model's records belong to tenants on this panel (its resource is scoped
     * to the tenant, or the model has the panel's ownership relationship).
     *
     * @param  class-string<Model>  $model
     */
    public static function isTenantScoped(Panel $panel, string $model): bool
    {
        if (! $panel->hasTenancy()) {
            return false;
        }

        $resource = self::resourceFor($panel, $model);

        if ($resource !== null) {
            return $resource::isScopedToTenant() && method_exists($model, $resource::getTenantOwnershipRelationshipName());
        }

        return method_exists($model, $panel->getTenantOwnershipRelationshipName());
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function tenantRelationshipName(Panel $panel, string $model): string
    {
        $resource = self::resourceFor($panel, $model);

        return $resource !== null ? $resource::getTenantOwnershipRelationshipName() : $panel->getTenantOwnershipRelationshipName();
    }

    /**
     * The `{tenant}` segment for a model in the given tenant (null: no channel, e.g. a
     * tenant-owned model outside a tenant).
     *
     * @param  class-string<Model>  $model
     */
    public static function tenantSegment(Panel $panel, string $model, ?Model $tenant): ?string
    {
        if (! self::isTenantScoped($panel, $model)) {
            return self::NO_TENANT;
        }

        return $tenant === null ? null : self::keySegment($tenant->getKey());
    }

    /**
     * The private channel of a model's changes (without the `private-` prefix).
     *
     * @param  class-string<Model>  $model
     */
    public static function forModel(Panel $panel, string $model, ?Model $tenant = null): ?string
    {
        $tenantSegment = self::tenantSegment($panel, ltrim($model, '\\'), $tenant);

        return $tenantSegment === null ? null : "alfakit.{$panel->getId()}.{$tenantSegment}.".self::modelSegment($model);
    }

    /**
     * The presence channel of a record (without the `presence-` prefix).
     *
     * @param  class-string<resource>|null  $resource  The record page's resource: required for API resources
     */
    public static function forRecord(Panel $panel, Model $record, ?Model $tenant = null, ?string $resource = null): ?string
    {
        $key = self::keySegment($record->getKey());

        if ($resource !== null && is_subclass_of($resource, ApiResource::class)) {
            $tenantSegment = ! $panel->hasTenancy() ? self::NO_TENANT : ($tenant === null ? null : self::keySegment($tenant->getKey()));
            $base = $tenantSegment === null ? null : "alfakit.{$panel->getId()}.{$tenantSegment}.".self::modelSegment($resource);
        } else {
            $base = self::forModel($panel, $record::class, $tenant);
        }

        return $base === null || $key === null ? null : "{$base}.{$key}";
    }

    /**
     * The API resource of the panel a `{model}` segment names, if any.
     *
     * @return class-string<ApiResource>|null
     */
    private static function apiResourceFromSegment(Panel $panel, string $segment): ?string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $segment) !== 1) {
            return null;
        }

        $class = str_replace('-', '\\', $segment);

        foreach ($panel->getResources() as $resource) {
            if (ltrim($resource, '\\') === $class && is_subclass_of($resource, ApiResource::class)) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Whether more than one of the panel's resources use this model (several API resources
     * on the shared `ApiRecord`): the model alone doesn't tell which one a channel means.
     *
     * @param  class-string<Model>  $model
     */
    private static function isAmbiguous(Panel $panel, string $model): bool
    {
        $count = 0;

        foreach ($panel->getResources() as $resource) {
            if (ltrim($resource::getModel(), '\\') === $model) {
                $count++;
            }
        }

        return $count > 1;
    }

    // ---------------------------------------------------------------- Registration

    /**
     * Register the panel's channels on its broadcasting connection, for the panel's guard.
     * Called once per panel when the application boots (`realtime()` panels only).
     */
    public static function register(Panel $panel): void
    {
        $id = $panel->getId();

        if (isset(self::$registered[$id])) {
            return;
        }

        self::$registered[$id] = true;

        $broadcaster = Broadcast::connection($panel->getBroadcastConnection());
        $options = ['guards' => [$panel->getAuthGuard()]];

        $broadcaster->channel(
            "alfakit.{$id}.{tenant}.{model}",
            static fn (mixed $user, string $tenant, string $model): bool => $user instanceof Authenticatable && self::authorizeModel($id, $user, $tenant, $model),
            $options,
        );

        // The user's own channel for live notifications (Laravel's `App.Models.User.{id}`),
        // unless the application defines it (`routes/channels.php`).
        $userModel = config('auth.providers.'.config('auth.guards.'.$panel->getAuthGuard().'.provider').'.model');

        if (is_string($userModel) && class_exists($userModel) && ! method_exists($userModel, 'receivesBroadcastNotificationsOn')) {
            $pattern = str_replace('\\', '.', $userModel).'.{id}';

            if (! $broadcaster->getChannels()->has($pattern)) {
                $broadcaster->channel($pattern, static fn (mixed $user, string $key): bool => $user instanceof Authenticatable && (string) $user->getAuthIdentifier() === $key, $options);
            }
        }

        $broadcaster->channel(
            "alfakit.{$id}.{tenant}.{model}.{record}",
            static fn (mixed $user, string $tenant, string $model, string $record): array|false => $user instanceof Authenticatable ? self::authorizeRecord($id, $user, $tenant, $model, $record) : false,
            $options,
        );
    }

    /** Whether a panel's channels are registered (tests, `about`). */
    public static function isRegistered(string $panel): bool
    {
        return isset(self::$registered[$panel]);
    }

    /** Forget registrations and caches (tests). */
    public static function flush(): void
    {
        self::$registered = [];
        self::$resources = [];
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * May the user follow changes of a model on this panel (and tenant)?
     */
    public static function authorizeModel(string $panelId, Authenticatable $user, string $tenant, string $model): bool
    {
        $context = self::bootContext($panelId, $user, $tenant, $model);

        if ($context === null) {
            return false;
        }

        [$class, $resource] = $context;

        return $resource !== null ? $resource::canViewAny() : Authorization::allows('viewAny', $class);
    }

    /**
     * May the user join a record's presence channel? Returns the member info shared with
     * the other users on the record (name and avatar), or false.
     *
     * @return array{id: mixed, name: string, avatar: string|null}|false
     */
    public static function authorizeRecord(string $panelId, Authenticatable $user, string $tenant, string $model, string $key): array|false
    {
        $context = self::bootContext($panelId, $user, $tenant, $model);

        if ($context === null) {
            return false;
        }

        [$class, $resource] = $context;
        $panel = alfakit()->getCurrentOrDefaultPanel();
        $record = self::findRecord($panel, $class, $resource, $key);

        if ($record === null) {
            return false;
        }

        $allowed = $resource !== null
            ? $resource::canView($record) || $resource::canEdit($record)
            : Authorization::allows('view', $record) || Authorization::allows('update', $record);

        return $allowed ? self::member($panel, $user) : false;
    }

    /**
     * What other users see of a member of a presence channel.
     *
     * @return array{id: mixed, name: string, avatar: string|null}
     */
    public static function member(Panel $panel, Authenticatable $user): array
    {
        return [
            'id' => $user->getAuthIdentifier(),
            'name' => $panel->getUserName($user),
            'avatar' => $panel->getUserAvatarUrl($user),
        ];
    }

    /**
     * Make the channel's panel and tenant current, after checking the user may use them.
     * Returns the model class and its resource, or null to deny.
     *
     * @return array{0: class-string<Model>, 1: class-string<resource>|null}|null
     */
    private static function bootContext(string $panelId, Authenticatable $user, string $tenantSegment, string $modelSegment): ?array
    {
        if (! alfakit()->hasPanel($panelId)) {
            return null;
        }

        $panel = alfakit()->getPanel($panelId);

        if (! $panel->isRealtimeConfigured()) {
            return null;
        }

        $class = self::modelFromSegment($modelSegment);

        if ($class !== null) {
            $resource = self::resourceFor($panel, $class);

            if ($resource !== null && is_subclass_of($resource, ApiResource::class) && self::isAmbiguous($panel, $class)) {
                return null;
            }
        } else {
            $resource = self::apiResourceFromSegment($panel, $modelSegment);

            if ($resource === null) {
                return null;
            }

            $class = ltrim($resource::getModel(), '\\');
        }

        // API resources hand the tenant to their data source: on tenancy panels their
        // channels always name (and check) it.
        $isApi = $resource !== null && is_subclass_of($resource, ApiResource::class);

        // The panel guard's user is the one being authorised (the channel's `guards` option).
        if ($panel->auth()->user()?->getAuthIdentifier() !== $user->getAuthIdentifier()) {
            return null;
        }

        if ($user instanceof CanAccessPanel) {
            if (! $user->canAccessPanel($panel)) {
                return null;
            }
        } elseif (! app()->isLocal() && ! app()->runningUnitTests()) {
            return null;
        }

        alfakit()->setCurrentPanel($panel);

        if (! $panel->hasRealtime()) {
            return null;
        }

        $tenant = null;

        if ($isApi ? $panel->hasTenancy() : self::isTenantScoped($panel, $class)) {
            $tenantModel = $panel->getTenantModel();

            if ($tenantModel === null || $tenantSegment === self::NO_TENANT) {
                return null;
            }

            $tenant = $tenantModel::query()->find($tenantSegment);

            // Fails closed: users without `HasTenants` can't follow any tenant's records.
            if (! $tenant instanceof Model || ! $user instanceof HasTenants || ! $user->canAccessTenant($tenant)) {
                return null;
            }
        } elseif ($tenantSegment !== self::NO_TENANT) {
            return null;
        }

        alfakit()->setTenant($tenant, isQuiet: true);

        return [$class, $resource];
    }

    /**
     * @param  class-string<Model>  $class
     * @param  class-string<resource>|null  $resource
     */
    private static function findRecord(Panel $panel, string $class, ?string $resource, string $key): ?Model
    {
        // API resources have no query: their data source finds the record (in the tenant).
        if ($resource !== null && is_subclass_of($resource, ApiResource::class)) {
            try {
                return $resource::resolveRecordRouteBinding($key);
            } catch (DataSourceException) {
                return null;
            }
        }

        if ($resource !== null) {
            $query = $resource::getEloquentQuery();
        } else {
            $query = $class::query();
            $tenant = $panel->getTenant();

            if ($tenant !== null && self::isTenantScoped($panel, $class)) {
                self::scopeToTenant($query, self::tenantRelationshipName($panel, $class), $tenant);
            }
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        $record = $query->whereKey($key)->first();

        return $record instanceof Model ? $record : null;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private static function scopeToTenant(Builder $query, string $relationship, Model $tenant): void
    {
        $relation = $query->getModel()->{$relationship}();

        if ($relation instanceof BelongsTo) {
            $query->where($relation->getQualifiedForeignKeyName(), $tenant->getKey());

            return;
        }

        $query->whereHas($relationship, static fn (Builder $related) => $related->whereKey($tenant->getKey()));
    }
}
