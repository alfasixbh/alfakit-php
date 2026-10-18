<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Colors;

use Closure;

/**
 * Colours for every panel, registered from a service provider (Filament's
 * `FilamentColor::register()`). Values are any CSS colour or an 11-shade palette
 * (`Color::Indigo`, `Color::hex('#…')`); a closure is evaluated per request.
 *
 * ```php
 * Colors::register([
 *     'primary' => Color::Indigo,
 *     'brand' => '#ff5500',
 * ]);
 * ```
 *
 * Precedence, lowest first: the theme's colours, these, then `Panel::colors()`.
 */
final class Colors
{
    /** @var array<int, array<string, string|array<int, string>>|Closure> */
    private static array $registered = [];

    /**
     * @param  array<string, string|array<int, string>>|Closure  $colors
     */
    public static function register(array|Closure $colors): void
    {
        self::$registered[] = $colors;
    }

    /**
     * Every registered colour, closures resolved, later registrations winning.
     *
     * @param  (Closure(Closure): mixed)|null  $evaluate  evaluates closures (a panel's `evaluate()`)
     * @return array<string, string|array<int, string>>
     */
    public static function all(?Closure $evaluate = null): array
    {
        $colors = [];

        foreach (self::$registered as $entry) {
            if ($entry instanceof Closure) {
                $entry = $evaluate !== null ? $evaluate($entry) : $entry();
            }

            if (is_array($entry)) {
                $colors = [...$colors, ...$entry];
            }
        }

        /** @var array<string, string|array<int, string>> $colors */
        return $colors;
    }

    /**
     * Forget every registration (tests).
     */
    public static function flush(): void
    {
        self::$registered = [];
    }
}
