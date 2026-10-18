<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Clusters\Concerns;

use AlfaSix\Alfakit\Clusters\Cluster;

/**
 * Pages and resources that set `$cluster` leave the main navigation: they are listed in
 * the cluster's sub-navigation and their URLs start with the cluster's slug.
 */
trait BelongsToCluster
{
    /** @var class-string<Cluster>|null */
    protected static ?string $cluster = null;

    /**
     * @return class-string<Cluster>|null
     */
    public static function getCluster(): ?string
    {
        return static::$cluster;
    }

    /**
     * The route path prefix the cluster adds (`settings/`), or an empty string.
     */
    public static function getClusterRoutePrefix(): string
    {
        $cluster = static::getCluster();

        return $cluster === null ? '' : trim($cluster::getSlug(), '/').'/';
    }
}
