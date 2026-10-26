<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands\Support;

use AlfaSix\Alfakit\Commands\MakeThemeCommand;
use Composer\Autoload\ClassLoader;
use Composer\InstalledVersions;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

use function Laravel\Prompts\confirm;

/**
 * What the setup commands need to know about, and do to, the application: installed
 * Composer and npm packages, `.env` keys, and running composer, npm and artisan in a
 * subprocess (so packages required a moment ago are autoloaded). Subprocesses go through
 * the `Process` facade, so tests fake them with `Process::fake()`.
 */
final class AppEnvironment
{
    /** The starter kit's `resources/js/pages/Alfakit/Page.*` (written by `alfakit:install --starter-kit`). */
    public static function starterKitPageEntry(): ?string
    {
        foreach (['tsx', 'jsx', 'ts', 'js', 'vue', 'svelte'] as $extension) {
            $page = resource_path("js/pages/Alfakit/Page.{$extension}");

            if (file_exists($page)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Whether a Composer package is installed, read from `vendor/composer/installed.json`
     * on every call (a `composer require` earlier in the same command counts).
     */
    public static function hasComposerPackage(string $package): bool
    {
        $installed = base_path('vendor/composer/installed.json');

        if (is_file($installed)) {
            $data = json_decode((string) file_get_contents($installed), true);
            $packages = is_array($data) ? ($data['packages'] ?? $data) : [];

            foreach (is_array($packages) ? $packages : [] as $entry) {
                if (is_array($entry) && ($entry['name'] ?? null) === $package) {
                    return true;
                }
            }

            return false;
        }

        $composer = base_path('composer.json');

        if (is_file($composer)) {
            $data = json_decode((string) file_get_contents($composer), true);

            if (is_array($data) && (isset($data['require'][$package]) || isset($data['require-dev'][$package]))) {
                return true;
            }
        }

        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    /**
     * @return array<string, mixed>
     */
    public static function packageJson(): array
    {
        $path = base_path('package.json');
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    public static function hasNpmPackage(string $package): bool
    {
        $json = self::packageJson();

        return isset($json['dependencies'][$package]) || isset($json['devDependencies'][$package]);
    }

    /**
     * Whether the app uses AlfaKit outside panels: `resources/css/app.css` imports a
     * renderer's `standalone.css` (written by `alfakit:install --standalone`).
     */
    public static function usesStandaloneStyles(): bool
    {
        $css = resource_path('css/app.css');

        return is_file($css) && str_contains((string) file_get_contents($css), 'standalone.css');
    }

    /**
     * The Inertia adapter the app uses: react, vue, svelte or null.
     */
    public static function adapter(): ?string
    {
        return match (true) {
            self::hasNpmPackage('@inertiajs/react') => 'react',
            self::hasNpmPackage('@inertiajs/vue3') => 'vue',
            self::hasNpmPackage('@inertiajs/svelte') => 'svelte',
            default => null,
        };
    }

    /**
     * A Laravel starter kit's app layout (`resources/js/layouts/app-layout.*`).
     */
    public static function starterKitLayout(): ?string
    {
        foreach (['tsx', 'jsx', 'vue', 'svelte'] as $extension) {
            foreach (['layouts/app-layout', 'layouts/AppLayout', 'layouts/app-layout/index'] as $name) {
                $path = resource_path("js/{$name}.{$extension}");

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Why the app has no Inertia frontend of its own for `$adapter` (the adapter package is not
     * in package.json, or the root view of its Inertia middleware is missing), or null when it
     * has one. The standalone install and the auth kit render the app's own Inertia pages.
     */
    public static function missingInertiaFrontend(string $adapter): ?string
    {
        $package = ['react' => '@inertiajs/react', 'vue' => '@inertiajs/vue3', 'svelte' => '@inertiajs/svelte'][$adapter] ?? '@inertiajs/'.$adapter;

        if (! self::hasNpmPackage($package)) {
            return "{$package} is not in package.json";
        }

        $view = resource_path('views/'.str_replace('.', '/', self::inertiaRootView()).'.blade.php');

        if (! is_file($view)) {
            return 'no Inertia root view: '.str_replace(base_path().'/', '', $view).' is missing';
        }

        return null;
    }

    /**
     * The root view of the app's Inertia middleware (`php artisan inertia:middleware`), `app` by default.
     */
    private static function inertiaRootView(string $middleware = 'App\\Http\\Middleware\\HandleInertiaRequests'): string
    {
        if (! class_exists($middleware)) {
            return 'app';
        }

        $rootView = (new ReflectionClass($middleware))->getDefaultProperties()['rootView'] ?? 'app';

        return is_string($rootView) && $rootView !== '' ? $rootView : 'app';
    }

    public static function hasFortify(): bool
    {
        return self::hasComposerPackage('laravel/fortify') || is_file(config_path('fortify.php'));
    }

    /**
     * Ask before Composer may change the app's other packages (interactive runs); scripted
     * runs (`--no-interaction`) allow it and say so in the output.
     */
    public static function allowDependencyChanges(Command $command): bool
    {
        if ((bool) $command->option('no-interaction')) {
            return true;
        }

        return confirm(
            'Composer can only install this by updating or downgrading packages your app already has. Allow that?',
            true,
            hint: 'It shows the changes. Answer no to keep your packages and install it yourself later.',
        );
    }

    /**
     * @param  array<int, string>  $packages
     * @param  (callable(): bool)|null  $allowDependencyChanges  Asked before the retry that may update or downgrade the app's packages; without it, the retry runs
     */
    public static function composerRequire(array $packages, bool $dev = false, ?callable $output = null, ?callable $allowDependencyChanges = null): ProcessResult
    {
        $command = ['composer', 'require', ...$packages, ...($dev ? ['--dev'] : []), '--no-interaction'];
        $callback = $output === null ? null : static fn (string $type, string $buffer) => $output($buffer);
        $result = Process::path(base_path())->timeout(900)->run($command, $callback);

        // A package that needs an older version of a locked dependency (laravel/reverb wants
        // guzzlehttp/psr7 ^2 while a new Laravel 13 app locks 3.x through guzzle 8) fails a
        // partial update. Composer's own advice is to allow dependency changes: retry once so.
        if ($result->failed() && str_contains($result->output().$result->errorOutput(), '--with-all-dependencies')) {
            if ($allowDependencyChanges !== null && ! $allowDependencyChanges()) {
                return $result;
            }

            if ($output !== null) {
                $output(PHP_EOL.'Retrying with --with-all-dependencies: Composer may update or downgrade packages your app already has (listed below). To undo, restore composer.json and composer.lock from git and run composer install.'.PHP_EOL);
            }

            $result = Process::path(base_path())->timeout(900)->run([...$command, '--with-all-dependencies'], $callback);
        }

        if ($result->successful()) {
            self::refreshAutoloader();
        }

        return $result;
    }

    /**
     * Teach the running process's Composer autoloader the classes of packages that a
     * `composer require` subprocess just installed, so later steps of the same command
     * (which may load a model that now uses one of their traits) can autoload them.
     */
    public static function refreshAutoloader(?string $vendorDir = null): void
    {
        $vendorDir = rtrim($vendorDir ?? base_path('vendor'), '/');
        $composer = $vendorDir.'/composer';

        if (! class_exists(ClassLoader::class) || ! is_dir($composer)) {
            return;
        }

        $loader = ClassLoader::getRegisteredLoaders()[$vendorDir] ?? null;

        if ($loader === null) {
            return;
        }

        foreach (is_file($composer.'/autoload_namespaces.php') ? (array) require $composer.'/autoload_namespaces.php' : [] as $prefix => $paths) {
            $loader->set((string) $prefix, $paths);
        }

        foreach (is_file($composer.'/autoload_psr4.php') ? (array) require $composer.'/autoload_psr4.php' : [] as $prefix => $paths) {
            $loader->setPsr4((string) $prefix, $paths);
        }

        if (is_file($composer.'/autoload_classmap.php')) {
            $loader->addClassMap((array) require $composer.'/autoload_classmap.php');
        }

        // The loader remembers classes it failed to find; forget them so they are looked up again.
        (fn () => $this->missingClasses = [])->call($loader);
    }

    /**
     * @param  array<int, string>  $packages
     */
    public static function composerRemove(array $packages, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            ['composer', 'remove', ...$packages, '--no-interaction'],
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * @param  array<int, string>  $packages
     * @return array<int, string>
     */
    public static function npmInstallCommand(array $packages, bool $dev = false): array
    {
        $manager = MakeThemeCommand::detectPackageManager();

        return match ($manager) {
            'npm' => ['npm', 'install', ...($dev ? ['--save-dev'] : []), ...$packages],
            default => [$manager, 'add', ...($dev ? ['-D'] : []), ...$packages],
        };
    }

    /**
     * @param  array<int, string>  $packages
     */
    public static function npmInstall(array $packages, bool $dev = false, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            self::npmInstallCommand($packages, $dev),
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * `<manager> run build` for the app's package manager (`yarn build` for Yarn).
     *
     * @return array<int, string>
     */
    public static function npmBuildCommand(): array
    {
        $manager = MakeThemeCommand::detectPackageManager();

        return $manager === 'yarn' ? ['yarn', 'build'] : [$manager, 'run', 'build'];
    }

    /**
     * Build the app's frontend (its `build` script).
     */
    public static function npmBuild(?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            self::npmBuildCommand(),
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * Whether the current frontend serves AlfaKit's Vite entries: a dev server runs, or the
     * last build's manifest contains the panel entry or the starter kit's AlfaKit page.
     */
    public static function frontendIncludesAlfakit(): bool
    {
        if (self::viteDevServerRunning()) {
            return true;
        }

        $manifest = public_path('build/manifest.json');
        $entries = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : null;

        if (! is_array($entries)) {
            return false;
        }

        foreach (array_keys($entries) as $entry) {
            if (preg_match('#^resources/js/(alfakit/|pages/Alfakit/)#', (string) $entry) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the Vite dev server is running (`public/hot`), so new entries are served live.
     * A `public/hot` whose server doesn't answer is left over (a dev server that was killed,
     * or one a tool started and stopped) and doesn't count.
     */
    public static function viteDevServerRunning(): bool
    {
        return is_file(public_path('hot')) && ! self::viteHotFileIsStale();
    }

    /**
     * `public/hot` names a dev server that doesn't answer. Laravel's `@vite` still reads the
     * file, so every page would load its scripts from that dead address.
     */
    public static function viteHotFileIsStale(): bool
    {
        $hot = public_path('hot');

        if (! is_file($hot)) {
            return false;
        }

        $url = parse_url(trim((string) file_get_contents($hot)));

        // Not a URL we can check: trust it.
        if (! is_array($url) || ! isset($url['host'], $url['port'])) {
            return false;
        }

        $connection = @fsockopen(trim($url['host'], '[]'), $url['port'], $code, $message, 0.5);

        if ($connection === false) {
            return true;
        }

        fclose($connection);

        return false;
    }

    /**
     * Run `php artisan …` in a fresh process.
     *
     * @param  array<int, string>  $arguments
     */
    public static function artisan(array $arguments, ?callable $output = null): ProcessResult
    {
        return Process::path(base_path())->timeout(900)->run(
            [PHP_BINARY, 'artisan', ...$arguments],
            $output === null ? null : static fn (string $type, string $buffer) => $output($buffer),
        );
    }

    /**
     * Whether `vendor:publish --tag=…` (with an optional `--provider=…`) only publishes
     * migrations that the app already has under any timestamp, so running it again would
     * add a second copy (`publishesMigrations()` stamps a new date every time). False when
     * the tag publishes anything else. A tag this process doesn't know (a package installed
     * a moment ago by a composer subprocess) is checked against `$package`'s own
     * `database/migrations` in vendor/, when given (W-S1-07: remove, then add again).
     *
     * @param  array<int, string>  $command
     */
    public static function migrationsAlreadyPublished(array $command, ?string $package = null): bool
    {
        if (($command[0] ?? null) !== 'vendor:publish') {
            return false;
        }

        $option = static function (string $name) use ($command): ?string {
            foreach ($command as $argument) {
                if (str_starts_with($argument, "--{$name}=")) {
                    return substr($argument, strlen($name) + 3);
                }
            }

            return null;
        };

        $tag = $option('tag');

        if ($tag === null) {
            return false;
        }

        $paths = ServiceProvider::pathsToPublish($option('provider'), $tag);
        $migrations = rtrim(database_path('migrations'), '/');

        if ($paths === [] && $package !== null && is_dir($source = base_path("vendor/{$package}/database/migrations"))) {
            $paths = [$source => $migrations];
        }
        $names = [];

        $isMigrationsDirectory = static fn (string $path): bool => ($path = rtrim($path, '/')) === $migrations || str_ends_with($path, '/database/migrations');

        foreach ($paths as $from => $to) {
            // A directory of migrations (plugins), or single files (core's `publishesMigrations()` tags).
            if (is_dir($from) && $isMigrationsDirectory((string) $to)) {
                foreach (glob(rtrim($from, '/').'/*.php') ?: [] as $file) {
                    $names[] = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file));
                }

                continue;
            }

            if (is_file($from) && str_ends_with($from, '.php') && $isMigrationsDirectory(dirname((string) $to))) {
                $names[] = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($from));

                continue;
            }

            return false;
        }

        if ($names === []) {
            return false;
        }

        $existing = array_map(
            static fn (string $file): string => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($file)),
            glob($migrations.'/*.php') ?: [],
        );

        return array_diff($names, $existing) === [];
    }

    /**
     * The raw value of a `.env` key, or null when the key is absent.
     */
    public static function envValue(string $key, ?string $file = null): ?string
    {
        $path = $file ?? base_path('.env');

        if (! is_file($path)) {
            return null;
        }

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', (string) file_get_contents($path), $match) !== 1) {
            return null;
        }

        $value = trim($match[1]);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * Set `.env`-style keys. Existing keys keep their value unless `$overwrite`; a missing
     * file is created only when `$create`. `$raw` writes values as given (for
     * `"${OTHER_KEY}"` references).
     *
     * @param  array<string, string>  $variables
     * @return array<int, string> The keys that were written.
     */
    public static function writeEnv(array $variables, ?string $file = null, bool $overwrite = false, bool $create = false, bool $raw = false): array
    {
        $path = $file ?? base_path('.env');

        if (! is_file($path)) {
            if (! $create) {
                return [];
            }

            file_put_contents($path, '');
        }

        $contents = (string) file_get_contents($path);
        $written = [];
        $appended = [];

        foreach ($variables as $key => $value) {
            $line = $key.'='.($raw ? $value : self::quoteEnvValue($value));
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                if ($overwrite) {
                    $contents = (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1);
                    $written[] = $key;
                }

                continue;
            }

            $appended[] = $line;
            $written[] = $key;
        }

        if ($appended !== []) {
            $contents = rtrim($contents)."\n\n".implode("\n", $appended)."\n";
            $contents = ltrim($contents, "\n");
        }

        file_put_contents($path, $contents);

        return $written;
    }

    /**
     * Quote a value so phpdotenv reads it back verbatim (no `${…}` interpolation).
     */
    public static function quoteEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_\-.:\/+=@,]+$/', $value) === 1) {
            return $value;
        }

        if (! str_contains($value, "'")) {
            return "'{$value}'";
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
    }
}
