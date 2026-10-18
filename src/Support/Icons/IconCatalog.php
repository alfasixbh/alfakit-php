<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Icons;

use AlfaSix\Alfakit\Support\Icons;

/**
 * The icons a set can list, for the IconPicker and `alfakit:icons --search`: Lucide (the
 * names the renderers draw, from `resources/icons/lucide.php`), registered Iconify sets,
 * registered directory sets and the Heroicon names. Closure sets can't be listed.
 *
 * Every entry maps the value to store (`house`, `mdi-home`, `heroicon-o-user`) to search
 * keywords (alias names).
 */
final class IconCatalog
{
    public const string LUCIDE = 'lucide';

    /** @var array<string, string>|null */
    private static ?array $lucide = null;

    /** @var array<string, array<string, string>> */
    private static array $memo = [];

    /**
     * The sets that can be listed: `lucide` and every registered Iconify or directory set.
     *
     * @return list<string>
     */
    public static function defaultSets(): array
    {
        $sets = [self::LUCIDE];

        foreach (Icons::getSets() as $prefix => $source) {
            if ($source instanceof IconifySet || is_string($source)) {
                $sets[] = $prefix;
            }
        }

        return $sets;
    }

    /**
     * Whether a set key can be listed (`lucide`, a registered listable set or `heroicon-o|s|m|c`).
     */
    public static function isListable(string $set): bool
    {
        $source = Icons::getSets()[$set] ?? null;

        return $set === self::LUCIDE || $source instanceof IconifySet || is_string($source) || preg_match('/^heroicon-[osmc]$/', $set) === 1;
    }

    public static function label(string $set): string
    {
        $source = Icons::getSets()[$set] ?? null;

        return match (true) {
            $set === self::LUCIDE => 'Lucide',
            $source instanceof IconifySet => $source->label(),
            preg_match('/^heroicon-([osmc])$/', $set, $matches) === 1 && $source === null => 'Heroicons ('.['o' => 'outline', 's' => 'solid', 'm' => 'mini', 'c' => 'micro'][$matches[1]].')',
            default => $set,
        };
    }

    /**
     * Value → keywords for every icon of a set.
     *
     * @return array<string, string>
     */
    public static function icons(string $set): array
    {
        if (isset(self::$memo[$set])) {
            return self::$memo[$set];
        }

        $source = Icons::getSets()[$set] ?? null;
        $icons = [];

        if ($set === self::LUCIDE) {
            foreach (self::lucide() as $name => $keywords) {
                // A registered set whose prefix claims the name (`flag` → `flag-off`) would
                // draw something else, so the Lucide icon is stored as `lucide-flag-off`.
                $icons[Icons::setPrefixFor($name) === null ? $name : 'lucide-'.$name] = $keywords;
            }
        } elseif ($source instanceof IconifySet) {
            foreach ($source->names() as $name => $keywords) {
                $icons[$set.'-'.$name] = $keywords;
            }
        } elseif (is_string($source)) {
            $icons = self::fromDirectory($set, $source);
        } elseif ($source === null && preg_match('/^heroicon-[osmc]$/', $set) === 1) {
            foreach (array_keys(HeroiconMap::MAP) as $name) {
                $icons[$set.'-'.$name] = '';
            }
        }

        if (count(self::$memo) > 20) {
            self::$memo = [];
        }

        return self::$memo[$set] = $icons;
    }

    /**
     * Rank icons for a search: exact name, exact alias, name prefix, word prefix, substring,
     * alias substring. The set prefix is ignored (`home` finds `mdi-home` first), and spaces
     * count as dashes (`arrow up` finds `arrow-up`). An empty search keeps the given order.
     *
     * @param  array<string, string>  $icons  value => keywords
     * @return list<string>
     */
    public static function search(array $icons, string $search, ?string $prefix = null): array
    {
        $term = trim((string) preg_replace('/[\s_]+/', '-', mb_strtolower(trim($search))), '-');

        if ($term === '') {
            return array_map(strval(...), array_keys($icons));
        }

        $ranked = [];

        foreach ($icons as $value => $keywords) {
            $value = (string) $value;
            $name = self::withoutPrefix($value, $prefix);
            $rank = match (true) {
                $name === $term || $value === $term => 0,
                $keywords !== '' && in_array($term, explode(' ', $keywords), true) => 1,
                str_starts_with($name, $term) || str_starts_with($value, $term) => 2,
                str_contains('-'.$name, '-'.$term) => 3,
                str_contains($value, $term) => 4,
                $keywords !== '' && str_contains($keywords, $term) => 5,
                default => null,
            };

            if ($rank !== null) {
                $ranked[$rank][] = $value;
            }
        }

        ksort($ranked);

        return $ranked === [] ? [] : array_merge(...array_values($ranked));
    }

    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * @return array<string, string>
     */
    public static function lucide(): array
    {
        if (self::$lucide === null) {
            /** @var array<string, string> $names */
            $names = require dirname(__DIR__, 3).'/resources/icons/lucide.php';
            self::$lucide = $names;
        }

        return self::$lucide;
    }

    private static function withoutPrefix(string $value, ?string $prefix): string
    {
        if ($prefix !== null && str_starts_with($value, $prefix.'-')) {
            return substr($value, strlen($prefix) + 1);
        }

        if (str_starts_with($value, 'lucide-')) {
            return substr($value, 7);
        }

        $set = Icons::setPrefixFor($value);

        return $set === null ? $value : substr($value, strlen($set) + 1);
    }

    /**
     * @return array<string, string>
     */
    private static function fromDirectory(string $prefix, string $directory): array
    {
        $files = glob(rtrim($directory, '/\\').'/*.svg') ?: [];
        $names = array_map(static fn (string $file): string => basename($file, '.svg'), $files);

        // blade-heroicons keeps `o-user.svg`, `s-user.svg`… in one directory.
        if (preg_match('/-([osmc])$/', $prefix, $matches) === 1) {
            $variant = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, $matches[1].'-')));

            if ($variant !== []) {
                $names = array_map(static fn (string $name): string => substr($name, 2), $variant);
            }
        }

        $icons = [];

        foreach ($names as $name) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $name) === 1) {
                $icons[$prefix.'-'.$name] = '';
            }
        }

        ksort($icons);

        return $icons;
    }
}
