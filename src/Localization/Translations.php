<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Localization;

/**
 * Registry of translation directories, layered per namespace.
 *
 * Laravel allows a single directory per translation namespace. Alfakit keeps a list, so
 * the core package ships English, `alfasix/alfakit-translations` ships the other
 * languages, plugins add their own, and the application overrides any of them from
 * `lang/vendor/{namespace}`:
 *
 * ```php
 * // In a plugin's or language pack's service provider:
 * Translations::register(__DIR__.'/../lang');                   // into `alfakit::`
 * Translations::register(__DIR__.'/../lang', namespace: 'maps'); // a plugin's own namespace
 * ```
 *
 * Later registrations win over earlier ones; `priority` changes the order explicitly.
 */
final class Translations
{
    /** @var array<string, array<int, array{path: string, priority: int}>> */
    private static array $paths = [];

    public static function register(string $path, string $namespace = 'alfakit', int $priority = 0): void
    {
        $path = rtrim($path, '/\\');

        foreach (self::$paths[$namespace] ?? [] as $entry) {
            if ($entry['path'] === $path) {
                return;
            }
        }

        self::$paths[$namespace][] = ['path' => $path, 'priority' => $priority];
    }

    public static function isManaged(?string $namespace): bool
    {
        return $namespace !== null && isset(self::$paths[$namespace]);
    }

    /**
     * Directories for a namespace, lowest precedence first.
     *
     * @return array<int, string>
     */
    public static function paths(string $namespace = 'alfakit'): array
    {
        $entries = self::$paths[$namespace] ?? [];

        // Stable sort: equal priorities keep registration order.
        $indexed = array_map(null, array_keys($entries), $entries);
        usort($indexed, static fn (array $a, array $b): int => [$a[1]['priority'], $a[0]] <=> [$b[1]['priority'], $b[0]]);

        return array_map(static fn (array $item): string => $item[1]['path'], $indexed);
    }

    /**
     * @return array<int, string>
     */
    public static function namespaces(): array
    {
        return array_keys(self::$paths);
    }

    /**
     * Locales that have at least one translation file for the namespace.
     *
     * @return array<int, string>
     */
    public static function availableLocales(string $namespace = 'alfakit', ?string $overridesPath = null): array
    {
        $locales = [];
        $directories = self::paths($namespace);

        if ($overridesPath !== null) {
            $directories[] = $overridesPath;
        }

        foreach ($directories as $directory) {
            foreach (glob($directory.'/*', GLOB_ONLYDIR) ?: [] as $localeDirectory) {
                $locales[basename($localeDirectory)] = true;
            }
        }

        $locales = array_keys($locales);
        sort($locales);

        return $locales;
    }

    /**
     * Group names (file names without `.php`) defined for a locale, or for English.
     *
     * @return array<int, string>
     */
    public static function groups(string $namespace = 'alfakit', string $locale = 'en'): array
    {
        $groups = [];

        foreach (self::paths($namespace) as $directory) {
            foreach (glob($directory.'/'.$locale.'/*.php') ?: [] as $file) {
                $groups[basename($file, '.php')] = true;
            }
        }

        $groups = array_keys($groups);
        sort($groups);

        return $groups;
    }

    /** @internal Used by tests. */
    public static function flush(): void
    {
        self::$paths = [];
    }
}
