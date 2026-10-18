<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pwa;

use AlfaSix\Alfakit\Panel;
use Illuminate\Foundation\Vite;
use Throwable;

/**
 * Builds the panel's service worker: the script in `resources/pwa/service-worker.js`
 * with its configuration (scope, offline page, app shell) in front. The cache version is
 * a hash of everything it caches, so a new build ships a new worker and the renderers
 * offer "Reload".
 */
final class ServiceWorker
{
    /** Bump when the worker script changes in a way the hash does not see. */
    public const VERSION = '1';

    public static function script(Panel $panel): string
    {
        $config = self::config($panel);
        $template = (string) file_get_contents(__DIR__.'/../../resources/pwa/service-worker.js');

        return 'const CONFIG = '.json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG).";\n".$template;
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(Panel $panel): array
    {
        $precache = self::appShell($panel);
        $locale = $panel->getCurrentLocale();
        $offline = $panel->getPwaOfflineUrl();
        $icon = $panel->getPwaAppleTouchIcon();

        foreach ($panel->getPwaIcons() as $entry) {
            if (! str_contains($entry['src'], '://')) {
                $precache[] = $entry['src'];
            }
        }

        $precache = array_values(array_unique($precache));

        $config = [
            'panel' => $panel->getId(),
            'scope' => $panel->getPwaScope(),
            'offline' => $offline,
            'precache' => $precache,
            'internal' => trim((string) config('alfakit.system_route_prefix', '_alfakit'), '/'),
            'build' => [self::buildPath()],
            'name' => $panel->getPwaName(),
            'icon' => $icon,
            'lang' => $locale->getTag(),
            'dir' => $locale->getDirection()->value,
        ];

        // A new build, panel configuration or worker version gives a new cache.
        $config['version'] = substr(md5(self::VERSION.'|'.json_encode($config).'|'.(app(Vite::class)->manifestHash(self::buildDirectory()) ?? '')), 0, 12);

        return $config;
    }

    /**
     * URLs of the built entry chunks of the panel (and their static imports and CSS) from
     * the Vite manifest. Empty while the Vite dev server runs.
     *
     * @return array<int, string>
     */
    public static function appShell(Panel $panel): array
    {
        $vite = app(Vite::class);

        if ($vite->isRunningHot()) {
            return [];
        }

        $directory = self::buildDirectory();
        $path = public_path($directory.'/manifest.json');

        if (! is_file($path)) {
            return [];
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($manifest)) {
            return [];
        }

        $files = [];
        $seen = [];
        $visit = static function (string $key) use (&$visit, &$files, &$seen, $manifest): void {
            if (isset($seen[$key]) || ! is_array($manifest[$key] ?? null)) {
                return;
            }

            $seen[$key] = true;
            $chunk = $manifest[$key];

            if (is_string($chunk['file'] ?? null)) {
                $files[] = $chunk['file'];
            }

            foreach ((array) ($chunk['css'] ?? []) as $css) {
                if (is_string($css)) {
                    $files[] = $css;
                }
            }

            foreach ((array) ($chunk['imports'] ?? []) as $import) {
                if (is_string($import)) {
                    $visit($import);
                }
            }
        };

        foreach ($panel->getViteEntries() as $entry) {
            $visit($entry);
        }

        $base = self::buildPath();

        return array_values(array_unique(array_map(static fn (string $file): string => $base.$file, $files)));
    }

    private static function buildDirectory(): string
    {
        $vite = app(Vite::class);

        try {
            $directory = (fn (): mixed => $this->buildDirectory)->call($vite);
        } catch (Throwable) {
            $directory = 'build';
        }

        return trim(is_string($directory) ? $directory : 'build', '/');
    }

    /** The public URL path of the build directory (`/build/`). */
    private static function buildPath(): string
    {
        $path = (string) parse_url(asset(self::buildDirectory()), PHP_URL_PATH);

        return rtrim($path, '/').'/';
    }
}
