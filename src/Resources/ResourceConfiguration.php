<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources;

use AlfaSix\Alfakit\Resources\Resource as BaseResource;
use Closure;
use InvalidArgumentException;

/**
 * One registration of a configurable resource: `OrderResource::make('archived')` returns
 * the resource's configuration class (`Resource::$configurationClass`), whose fluent
 * options the resource reads back through `static::getConfiguration()` while that
 * registration serves the request. Register it like a resource:
 *
 * ```php
 * $panel->resources([
 *     OrderResource::class,                            // /orders
 *     OrderResource::make('archived')->archived(),     // /orders/archived
 *     OrderResource::make('drafts')->slug('drafts'),   // /drafts
 * ]);
 * ```
 *
 * Extend this class to add your own options.
 */
class ResourceConfiguration
{
    /** Route default that names the configuration a route belongs to. */
    public const ROUTE_PARAMETER = 'alfakitResourceConfiguration';

    protected ?string $slug = null;

    protected string|false|null $userOwnership = null;

    protected ?Closure $userOwnerKeyResolver = null;

    /**
     * @param  class-string<BaseResource>  $resource
     */
    final public function __construct(
        protected string $resource,
        protected string $key,
    ) {
        if ($key === '' || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new InvalidArgumentException("Resource configuration key [{$key}] may only contain letters, numbers, dashes and underscores.");
        }
    }

    /**
     * @param  class-string<BaseResource>  $resource
     */
    public static function make(string $resource, string $key): static
    {
        return new static($resource, $key);
    }

    /**
     * The URL path of this registration, replacing the default `{resource-slug}/{key}`.
     */
    public function slug(?string $slug): static
    {
        $this->slug = $slug === null ? null : trim($slug, '/');

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    /**
     * Scope this registration's records to the signed-in user (see
     * `Resource::scopeToUser()`): `OrderResource::make('mine')->scopeToUser('customer_id')`.
     * `false` opts out of the panel's `scopeToUser()`.
     *
     * @param  (Closure(\Illuminate\Contracts\Auth\Authenticatable): mixed)|null  $ownerKey
     */
    public function scopeToUser(string|false $ownership = 'user_id', ?Closure $ownerKey = null): static
    {
        $this->userOwnership = $ownership;
        $this->userOwnerKeyResolver = $ownerKey;

        return $this;
    }

    public function getUserOwnership(): string|false|null
    {
        return $this->userOwnership;
    }

    public function getUserOwnerKeyResolver(): ?Closure
    {
        return $this->userOwnerKeyResolver;
    }

    /**
     * @return class-string<BaseResource>
     */
    public function getResource(): string
    {
        return $this->resource;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
