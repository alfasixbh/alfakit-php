<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Assets;

use Closure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Vite;

/**
 * A PHP copy of the Vite manifest, written by `alfakit:optimize` (and `php artisan optimize`).
 *
 * Renderers load icons and translations as separate chunks, so an AlfaKit build's
 * `manifest.json` lists thousands of entries (about 1 MB). Laravel decodes that JSON on
 * every full page load and Inertia hashes it for the asset version; the PHP copy is served
 * from OPcache instead. It is only used while the manifest's size and modification time
 * still match, so a new build is picked up right away (from the JSON) until the next
 * `alfakit:optimize`.
 */
final class ViteManifest
{
    /** @var array<string, array{size: int, mtime: int, hash: string, manifest: array<string, mixed>}|false> */
    private static array $loaded = [];

    /**
     * The manifest path Laravel's Vite helper reads for a build directory.
     */
    public static function path(?string $buildDirectory = null): string
    {
        $vite = app(Vite::class);

        /** @var string */
        return (fn (?string $directory): string => $this->manifestPath($directory ?? $this->buildDirectory))->call($vite, $buildDirectory);
    }

    public static function cachePath(string $manifestPath): string
    {
        return rtrim((string) config('alfakit.cache_path'), '/').'/vite-manifest-'.hash('xxh3', $manifestPath).'.php';
    }

    /**
     * Write the PHP copy of a manifest. Returns false when there is no build.
     */
    public static function cache(string $manifestPath, ?Filesystem $files = null): bool
    {
        $files ??= new Filesystem;

        if (! is_file($manifestPath)) {
            return false;
        }

        $contents = (string) file_get_contents($manifestPath);
        $manifest = json_decode($contents, true);

        if (! is_array($manifest)) {
            return false;
        }

        clearstatcache(true, $manifestPath);

        $data = [
            'size' => (int) filesize($manifestPath),
            'mtime' => (int) filemtime($manifestPath),
            // The same value Inertia's `version()` computes from the file.
            'hash' => hash('xxh128', $contents),
            'manifest' => $manifest,
        ];

        $cachePath = self::cachePath($manifestPath);
        $files->ensureDirectoryExists(dirname($cachePath));
        $files->put($cachePath, '<?php return '.var_export($data, true).';'.PHP_EOL);

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cachePath, true);
        }

        unset(self::$loaded[$manifestPath]);

        return true;
    }

    public static function forget(string $manifestPath): void
    {
        @unlink(self::cachePath($manifestPath));
        unset(self::$loaded[$manifestPath]);
    }

    /**
     * Hand the cached manifest to Laravel's Vite helper, so `@vite` does not decode the
     * JSON. A no-op without a cache or when the manifest changed since it was written.
     */
    public static function prime(?string $buildDirectory = null): void
    {
        $path = self::path($buildDirectory);
        $cached = self::load($path);

        if ($cached === null) {
            return;
        }

        Closure::bind(static function () use ($path, $cached): void {
            static::$manifests[$path] ??= $cached['manifest'];
        }, null, Vite::class)();
    }

    /**
     * The cached xxh128 hash of a manifest (Inertia's asset version), or null without a
     * fresh cache.
     */
    public static function hash(string $manifestPath): ?string
    {
        return self::load($manifestPath)['hash'] ?? null;
    }

    /**
     * @return array{size: int, mtime: int, hash: string, manifest: array<string, mixed>}|null
     */
    private static function load(string $manifestPath): ?array
    {
        if (! array_key_exists($manifestPath, self::$loaded)) {
            $cachePath = self::cachePath($manifestPath);
            $data = is_file($cachePath) ? require $cachePath : null;

            self::$loaded[$manifestPath] = is_array($data) && isset($data['size'], $data['mtime'], $data['hash'], $data['manifest']) ? $data : false;
        }

        $data = self::$loaded[$manifestPath];

        if ($data === false) {
            return null;
        }

        // A new build since `alfakit:optimize`: the JSON is the truth again.
        $size = @filesize($manifestPath);
        $mtime = @filemtime($manifestPath);

        return $size === $data['size'] && $mtime === $data['mtime'] ? $data : null;
    }

    /**
     * Forget what this process loaded (tests, long-lived workers after a deploy).
     */
    public static function flush(): void
    {
        self::$loaded = [];
    }
}
