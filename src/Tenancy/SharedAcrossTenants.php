<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tenancy;

use Attribute;
use ReflectionClass;

/**
 * Marks a model as deliberately shared by every tenant (countries, currencies, plans…).
 * Tenancy never scopes such a model anyway; the attribute records the intent so
 * `php artisan alfakit:tenancy:check` reports it as OK instead of warning about it.
 *
 * ```php
 * #[SharedAcrossTenants]
 * class Currency extends Model {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SharedAcrossTenants
{
    /**
     * @param  class-string  $class
     */
    public static function appliesTo(string $class): bool
    {
        return class_exists($class) && (new ReflectionClass($class))->getAttributes(self::class) !== [];
    }
}
