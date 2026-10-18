<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Notifications;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * On tenancy panels, database notifications sent inside a tenant carry that tenant
 * (`alfakitTenant` in their data) and are listed, counted and cleared only in that tenant.
 * Notifications sent outside a tenant (or with `->forAllTenants()`) show everywhere.
 */
final class DatabaseNotificationScope
{
    public const KEY = 'alfakitTenant';

    /**
     * The value stored for a tenant: an opaque hash of its morph class and key, so it can
     * be matched in the JSON text on every database (`data` is a text column).
     */
    public static function marker(Model $tenant): string
    {
        return substr(hash('sha256', $tenant->getMorphClass().':'.$tenant->getKey()), 0, 32);
    }

    /**
     * @template TQuery
     *
     * @param  TQuery  $query  An Eloquent builder or relation over the notifications table.
     * @return TQuery
     */
    public static function apply(mixed $query): mixed
    {
        if (! $query instanceof Builder && ! $query instanceof Relation) {
            return $query;
        }

        $panel = alfakit()->getCurrentPanel();

        if ($panel === null || ! $panel->hasTenancy()) {
            return $query;
        }

        $tenant = alfakit()->getTenant();
        $tagged = '%"'.self::KEY.'":%';

        if ($tenant === null) {
            return $query->where('data', 'not like', $tagged);
        }

        $marker = '%"'.self::KEY.'":"'.self::marker($tenant).'"%';

        return $query->where(static fn ($query) => $query->where('data', 'not like', $tagged)->orWhere('data', 'like', $marker));
    }
}
