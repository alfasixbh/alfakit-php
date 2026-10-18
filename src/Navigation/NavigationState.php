<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation;

use Closure;
use ReflectionMethod;

/**
 * Splits the panel navigation into its structure (labels, URLs, icons: the same on every
 * page, so it is an Inertia once prop) and the state of the current request (the active
 * items and the badges). Items are identified by position: `group.item[.child…]`.
 *
 * While the panel defers its badges (`Panel::deferNavigationBadges()`), the navigation is
 * built without evaluating them: items that have one carry `deferredBadge` and the badges
 * arrive with the `alfakitNavigationBadges` deferred prop.
 */
final class NavigationState
{
    private static bool $splitting = false;

    private static bool $deferringBadges = false;

    /**
     * Run `$callback` (which serialises navigation groups) in split mode: items keep their
     * inactive `icon` and add `activeIcon`, and, when `$deferBadges` is true, leave their
     * badges unevaluated.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function serializing(bool $deferBadges, Closure $callback): mixed
    {
        $previous = [self::$splitting, self::$deferringBadges];
        self::$splitting = true;
        self::$deferringBadges = $deferBadges;

        try {
            return $callback();
        } finally {
            [self::$splitting, self::$deferringBadges] = $previous;
        }
    }

    public static function isSplitting(): bool
    {
        return self::$splitting;
    }

    public static function isDeferringBadges(): bool
    {
        return self::$deferringBadges;
    }

    /**
     * Give a page or resource navigation item its badge, unless badges are deferred: then
     * the item is only marked as having one when the class overrides `getNavigationBadge()`.
     *
     * @param  class-string  $class
     * @param  class-string  $base  The class that declares the default (null) badge.
     * @param  Closure(): array{0: string|int|null, 1: string|null, 2: string|null}  $resolve  Badge, colour and tooltip.
     */
    public static function applyBadge(NavigationItem $item, string $class, string $base, Closure $resolve): NavigationItem
    {
        if (self::$deferringBadges) {
            if ((new ReflectionMethod($class, 'getNavigationBadge'))->getDeclaringClass()->getName() !== $base) {
                $item->markBadgeDeferred();
            }

            return $item;
        }

        [$badge, $color, $tooltip] = $resolve();

        return $item->badge($badge, $color)->badgeTooltip($tooltip);
    }

    /**
     * Split serialised groups (built inside `serializing()`) into the structure and the
     * request state: `active` item ids and `badges` keyed by item id.
     *
     * @param  array<int, array<string, mixed>>  $groups
     * @return array{0: list<array<string, mixed>>, 1: array{active: list<string>, badges: array<string, array<string, mixed>>}}
     */
    public static function split(array $groups): array
    {
        $state = ['active' => [], 'badges' => []];
        $structure = [];

        foreach (array_values($groups) as $index => $group) {
            $group['items'] = self::splitItems(is_array($group['items'] ?? null) ? $group['items'] : [], (string) $index, $state);
            $structure[] = $group;
        }

        return [$structure, $state];
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  array{active: list<string>, badges: array<string, array<string, mixed>>}  $state
     * @return list<array<string, mixed>>
     */
    private static function splitItems(array $items, string $prefix, array &$state): array
    {
        $result = [];

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = "{$prefix}.{$index}";

            if (($item['active'] ?? false) === true) {
                $state['active'][] = $id;
            }

            $badge = array_filter([
                'badge' => $item['badge'] ?? null,
                'badgeColor' => $item['badgeColor'] ?? null,
                'badgeTooltip' => $item['badgeTooltip'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);

            if (isset($badge['badge'])) {
                $state['badges'][$id] = $badge;
            }

            unset($item['active'], $item['badge'], $item['badgeColor'], $item['badgeTooltip']);

            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = self::splitItems($item['children'], $id, $state);
            }

            $result[] = $item;
        }

        return $result;
    }
}
