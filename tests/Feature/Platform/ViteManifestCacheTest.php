<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Http\Middleware\HandleInertiaRequests;
use AlfaSix\Alfakit\Support\Assets\ViteManifest;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;

/** What Laravel's Vite helper holds for a manifest path (null until it read one). */
function viteManifestsLoaded(string $path): ?array
{
    return Closure::bind(static fn (): ?array => static::$manifests[$path] ?? null, null, Vite::class)();
}

function forgetViteManifests(): void
{
    Closure::bind(static function (): void {
        static::$manifests = [];
    }, null, Vite::class)();
}

/** Writes build/manifest.json with the given entries and a distinct modification time. */
function writeViteManifest(array $entries, int $mtime): string
{
    $path = public_path('build/manifest.json');
    (new Filesystem)->ensureDirectoryExists(dirname($path));
    file_put_contents($path, json_encode($entries));
    touch($path, $mtime);
    clearstatcache(true, $path);

    return $path;
}

beforeEach(function (): void {
    $this->publicPath = sys_get_temp_dir().'/alfakit-vite-'.bin2hex(random_bytes(4));
    app()->usePublicPath($this->publicPath);
    ViteManifest::flush();
    forgetViteManifests();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->publicPath);
    (new Filesystem)->deleteDirectory((string) config('alfakit.cache_path'));
    ViteManifest::flush();
    forgetViteManifests();
});

it('caches the Vite manifest as PHP with alfakit:optimize and hands it to Laravel\'s Vite helper', function (): void {
    $entries = ['resources/js/app.tsx' => ['file' => 'assets/app-abc.js', 'isEntry' => true]];
    $path = writeViteManifest($entries, time() - 60);

    $this->artisan('alfakit:optimize')->assertSuccessful();

    expect(ViteManifest::path())->toBe($path)
        ->and(is_file(ViteManifest::cachePath($path)))->toBeTrue()
        ->and(ViteManifest::hash($path))->toBe(hash_file('xxh128', $path))
        ->and(viteManifestsLoaded($path))->toBeNull();

    ViteManifest::prime();

    expect(viteManifestsLoaded($path))->toBe($entries);
});

it('ignores the cached manifest once a new build changed manifest.json', function (): void {
    $path = writeViteManifest(['a.js' => ['file' => 'assets/a-1.js']], time() - 120);
    ViteManifest::cache($path);

    // A new build: other content, another modification time.
    writeViteManifest(['a.js' => ['file' => 'assets/a-2.js'], 'b.js' => ['file' => 'assets/b-2.js']], time() - 30);

    expect(ViteManifest::hash($path))->toBeNull();

    ViteManifest::prime();

    expect(viteManifestsLoaded($path))->toBeNull();

    // `alfakit:optimize` again picks up the new build.
    $this->artisan('alfakit:optimize')->assertSuccessful();

    expect(ViteManifest::hash($path))->toBe(hash_file('xxh128', $path));
});

it('removes the cached manifest with alfakit:optimize-clear', function (): void {
    $path = writeViteManifest(['a.js' => ['file' => 'assets/a.js']], time() - 60);

    $this->artisan('alfakit:optimize')->assertSuccessful();
    expect(ViteManifest::hash($path))->not->toBeNull();

    $this->artisan('alfakit:optimize-clear')->assertSuccessful();

    expect(is_file(ViteManifest::cachePath($path)))->toBeFalse()
        ->and(ViteManifest::hash($path))->toBeNull();
});

it('writes nothing without a build', function (): void {
    $this->artisan('alfakit:optimize')->assertSuccessful();

    expect(is_file(ViteManifest::cachePath(ViteManifest::path())))->toBeFalse();
});

it('gives Inertia the same asset version with and without the cache', function (): void {
    $path = writeViteManifest(['a.js' => ['file' => 'assets/a.js']], time() - 60);
    $middleware = new HandleInertiaRequests;
    $request = Request::create('/admin');

    $uncached = $middleware->version($request);
    ViteManifest::cache($path);
    ViteManifest::flush();

    expect($uncached)->toBe(hash_file('xxh128', $path))
        ->and($middleware->version($request))->toBe($uncached);

    // `app.asset_url` decides the version, as in Inertia's middleware.
    config()->set('app.asset_url', 'https://cdn.example.com');

    expect($middleware->version($request))->toBe(hash('xxh128', 'https://cdn.example.com'));
});

it('primes the cached manifest on full page loads only', function (): void {
    $path = writeViteManifest(['a.js' => ['file' => 'assets/a.js']], time() - 60);
    $this->artisan('alfakit:optimize')->assertSuccessful();
    ViteManifest::flush();

    $this->get('/admin/login', ['X-Inertia' => 'true', 'X-Inertia-Version' => hash_file('xxh128', $path)]);

    expect(viteManifestsLoaded($path))->toBeNull();

    $this->get('/admin/login')->assertOk();

    expect(viteManifestsLoaded($path))->toBe(['a.js' => ['file' => 'assets/a.js']]);
});
