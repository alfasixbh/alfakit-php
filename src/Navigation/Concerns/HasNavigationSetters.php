<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation\Concerns;

use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use UnitEnum;

/**
 * Static setters for a page's, resource's or cluster's navigation item, e.g. from a
 * plugin's `register()` or a service provider: `UserResource::navigationGroup('Team')`.
 * They override the `$navigation*` properties for that class only (siblings that share
 * the parent's static properties are not affected).
 */
trait HasNavigationSetters
{
    /** @var array<class-string, array<string, mixed>> */
    protected static array $navigationOverrides = [];

    public static function navigationGroup(string|UnitEnum|null $group): void
    {
        static::$navigationOverrides[static::class]['group'] = $group;
    }

    public static function navigationLabel(?string $label): void
    {
        static::$navigationOverrides[static::class]['label'] = $label;
    }

    public static function navigationSort(?int $sort): void
    {
        static::$navigationOverrides[static::class]['sort'] = $sort;
    }

    public static function navigationIcon(string|BackedEnum|null $icon): void
    {
        static::$navigationOverrides[static::class]['icon'] = $icon;
    }

    /**
     * The parent item's key (a page or resource class) or label.
     */
    public static function navigationParentItem(?string $item): void
    {
        static::$navigationOverrides[static::class]['parentItem'] = $item;
    }

    /**
     * Forget the values set with the static setters (all classes, or one).
     *
     * @param  class-string|null  $class
     */
    public static function flushNavigationOverrides(?string $class = null): void
    {
        if ($class === null) {
            static::$navigationOverrides = [];
        } else {
            unset(static::$navigationOverrides[$class]);
        }
    }

    protected static function hasNavigationOverride(string $name): bool
    {
        return array_key_exists($name, static::$navigationOverrides[static::class] ?? []);
    }

    protected static function getNavigationOverride(string $name): mixed
    {
        return static::$navigationOverrides[static::class][$name] ?? null;
    }

    protected static function getNavigationIconOverride(): ?string
    {
        return Icons::resolve(static::getNavigationOverride('icon'));
    }
}
