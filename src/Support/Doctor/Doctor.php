<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Doctor;

use AlfaSix\Alfakit\Localization\TranslationChecker;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Versions\HasRecordVersions;
use AlfaSix\Alfakit\Widgets\Widget;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Runs the `alfakit:doctor` checks: versions, frontend packages and built assets, SSR,
 * panels and their resources, translations, storage links, queues, broadcasting, the
 * application key, debug mode, migrations of opted-in features and caches. Each check
 * explains what it found and how to fix it. Packages add checks with `Doctor::extend()`.
 */
class Doctor
{
    public const MIN_PHP = '8.4.0';

    public const MIN_LARAVEL = '13.0.0';

    public const MIN_INERTIA = '3.0.0';

    /** Plugins whose work runs in queued jobs. */
    public const QUEUED_PLUGINS = ['webhooks', 'workflows', 'backup'];

    /** @var array<int, Closure(DoctorEnvironment): iterable<DoctorCheck>> */
    protected static array $extensions = [];

    /** @var array<class-string, string>|null source file contents, per class */
    protected ?array $sources = null;

    public function __construct(protected DoctorEnvironment $env) {}

    /**
     * Add checks from a package or the application, e.g. in a service provider:
     * `Doctor::extend(fn () => [DoctorCheck::ok('Billing', 'Stripe key', 'set')])`.
     *
     * @param  Closure(DoctorEnvironment): iterable<DoctorCheck>  $checks
     */
    public static function extend(Closure $checks): void
    {
        static::$extensions[] = $checks;
    }

    public static function flushExtensions(): void
    {
        static::$extensions = [];
    }

    /**
     * @return list<DoctorCheck>
     */
    public function run(): array
    {
        $checks = [];

        foreach ([
            $this->environmentChecks(...),
            $this->frontendChecks(...),
            $this->ssrChecks(...),
            $this->panelChecks(...),
            $this->translationChecks(...),
            $this->storageChecks(...),
            $this->queueChecks(...),
            $this->broadcastingChecks(...),
            $this->migrationChecks(...),
            $this->cacheChecks(...),
        ] as $group) {
            array_push($checks, ...$this->guard($group));
        }

        foreach (static::$extensions as $extension) {
            array_push($checks, ...$this->guard(fn (): iterable => $extension($this->env)));
        }

        return $checks;
    }

    /**
     * A check that throws becomes an error line instead of stopping the doctor.
     *
     * @param  Closure(): iterable<DoctorCheck>  $group
     * @return list<DoctorCheck>
     */
    protected function guard(Closure $group): array
    {
        try {
            $checks = [];

            foreach ($group() as $check) {
                $checks[] = $check;
            }

            return $checks;
        } catch (Throwable $exception) {
            return [DoctorCheck::error('Doctor', 'Check failed', $exception->getMessage(), 'Fix the error above, then run alfakit:doctor again.')];
        }
    }

    // ---------------------------------------------------------------- Environment

    /**
     * @return iterable<DoctorCheck>
     */
    protected function environmentChecks(): iterable
    {
        $php = $this->env->phpVersion();

        yield version_compare($php, self::MIN_PHP, '>=')
            ? DoctorCheck::ok('Environment', 'PHP', $php)
            : DoctorCheck::error('Environment', 'PHP', "{$php} is older than ".self::MIN_PHP, 'Upgrade PHP to 8.4 or newer.');

        yield $this->env->hasExtension('intl')
            ? DoctorCheck::ok('Environment', 'ext-intl', 'loaded')
            : DoctorCheck::error('Environment', 'ext-intl', 'not loaded (needed for number, money and date formatting)', 'Install and enable the PHP intl extension.');

        $laravel = $this->env->laravelVersion();

        yield version_compare(self::normalizeVersion($laravel) ?? $laravel, self::MIN_LARAVEL, '>=')
            ? DoctorCheck::ok('Environment', 'Laravel', $laravel)
            : DoctorCheck::error('Environment', 'Laravel', "{$laravel} is older than ".self::MIN_LARAVEL, 'Upgrade laravel/framework to ^13.0.');

        $inertia = $this->env->composerVersion('inertiajs/inertia-laravel');
        $normalized = $inertia === null ? null : self::normalizeVersion($inertia);

        yield match (true) {
            $inertia === null => DoctorCheck::error('Environment', 'Inertia', 'inertiajs/inertia-laravel is not installed', 'composer require inertiajs/inertia-laravel'),
            $normalized !== null && version_compare($normalized, self::MIN_INERTIA, '<') => DoctorCheck::error('Environment', 'Inertia', "{$inertia} is older than ".self::MIN_INERTIA, 'composer require inertiajs/inertia-laravel:^3.5'),
            default => DoctorCheck::ok('Environment', 'Inertia', $inertia),
        };

        yield filled(config('app.key'))
            ? DoctorCheck::ok('Environment', 'APP_KEY', 'set')
            : DoctorCheck::error('Environment', 'APP_KEY', 'missing: context tokens and sessions cannot be encrypted', 'php artisan key:generate');

        $debug = (bool) config('app.debug');
        $environment = $this->env->environment();

        yield match (true) {
            $debug && $this->env->isProduction() => DoctorCheck::error('Environment', 'Debug mode', 'on in production: stack traces and dev tools are exposed', 'Set APP_DEBUG=false in the production .env.'),
            $debug => DoctorCheck::ok('Environment', 'Debug mode', "on ({$environment})"),
            default => DoctorCheck::ok('Environment', 'Debug mode', "off ({$environment})"),
        };
    }

    // ---------------------------------------------------------------- Frontend

    /**
     * @return iterable<DoctorCheck>
     */
    protected function frontendChecks(): iterable
    {
        $package = $this->env->readJson($this->env->basePath('package.json'));

        if ($package === null) {
            yield DoctorCheck::error('Frontend', 'package.json', 'not found', 'php artisan alfakit:install');

            return;
        }

        $dependencies = [...(array) ($package['dependencies'] ?? []), ...(array) ($package['devDependencies'] ?? [])];
        $renderer = null;

        foreach (['react', 'vue', 'svelte'] as $adapter) {
            if (isset($dependencies["@alfakit/{$adapter}"])) {
                $renderer = $adapter;

                break;
            }
        }

        if ($renderer === null) {
            yield DoctorCheck::error('Frontend', 'Renderer', 'no @alfakit/react, @alfakit/vue or @alfakit/svelte in package.json', 'php artisan alfakit:install');

            return;
        }

        $installed = $this->installedNpmVersion("@alfakit/{$renderer}");

        if ($installed === null) {
            yield DoctorCheck::error('Frontend', 'Renderer', "@alfakit/{$renderer} is in package.json but not installed", 'npm install (or pnpm install / yarn / bun install)');
        } else {
            yield $this->versionMatch("@alfakit/{$renderer}", $installed);
        }

        $core = $this->installedNpmVersion('@alfakit/core');

        if ($core !== null && $installed !== null && self::normalizeVersion($core) !== null && self::normalizeVersion($installed) !== null && ! self::isCompatible($core, $installed)) {
            yield DoctorCheck::warning('Frontend', '@alfakit/core', "{$core} does not match @alfakit/{$renderer} {$installed}", 'Reinstall so both resolve to the same release: npm update @alfakit/core @alfakit/'.$renderer);
        }

        yield from $this->assetChecks();
    }

    protected function versionMatch(string $package, string $installed): DoctorCheck
    {
        $php = $this->env->composerVersion('alfasix/alfakit');

        if ($php === null || self::normalizeVersion($php) === null || self::normalizeVersion($installed) === null) {
            return DoctorCheck::ok('Frontend', 'Renderer', "{$package} {$installed} (alfasix/alfakit ".($php ?? 'dev').', not compared)');
        }

        if (! self::isCompatible($php, $installed)) {
            return DoctorCheck::error(
                'Frontend',
                'Renderer',
                "{$package} {$installed} does not match alfasix/alfakit {$php}: the schema contract may differ",
                'npm install '.$package.'@'.self::constraintFor($php),
            );
        }

        return DoctorCheck::ok('Frontend', 'Renderer', "{$package} {$installed} matches alfasix/alfakit {$php}");
    }

    /**
     * The version of an npm package in `node_modules`, else in package-lock.json or
     * pnpm-lock.yaml; `linked` for workspace and path links.
     */
    public function installedNpmVersion(string $package): ?string
    {
        $installed = $this->env->readJson($this->env->basePath("node_modules/{$package}/package.json"));

        if ($installed !== null && is_string($installed['version'] ?? null)) {
            return $installed['version'];
        }

        $lock = $this->env->readJson($this->env->basePath('package-lock.json'));

        if ($lock !== null && is_string($lock['packages']["node_modules/{$package}"]['version'] ?? null)) {
            return $lock['packages']["node_modules/{$package}"]['version'];
        }

        $pnpm = $this->env->basePath('pnpm-lock.yaml');

        if (is_file($pnpm) && preg_match('/\''.preg_quote($package, '/').'\':\s*\n\s*specifier:[^\n]*\n\s*version:\s*([^\s(]+)/', (string) file_get_contents($pnpm), $match) === 1) {
            return str_starts_with($match[1], 'link:') ? 'linked' : $match[1];
        }

        return null;
    }

    /**
     * @return iterable<DoctorCheck>
     */
    protected function assetChecks(): iterable
    {
        if (is_file($this->env->publicPath('hot'))) {
            yield DoctorCheck::ok('Frontend', 'Assets', 'Vite dev server running (public/hot)');

            if ($this->env->isProduction()) {
                yield DoctorCheck::error('Frontend', 'Assets', 'public/hot exists in production: pages load scripts from the dev server', 'Delete public/hot and run npm run build.');
            }

            return;
        }

        $manifestPath = $this->env->publicPath('build/manifest.json');
        $manifest = $this->env->readJson($manifestPath);

        if ($manifest === null) {
            yield DoctorCheck::error('Frontend', 'Assets', 'public/build/manifest.json not found', 'npm run build (or npm run dev while developing)');

            return;
        }

        $missing = [];

        foreach (alfakit()->getPanels() as $panel) {
            if ($panel->isStarterKit()) {
                continue;
            }

            foreach ($panel->getViteEntries() as $entry) {
                if (! isset($manifest[$entry])) {
                    $missing[$entry] = $panel->getId();
                }
            }
        }

        if ($missing === []) {
            yield DoctorCheck::ok('Frontend', 'Assets', 'built; the manifest has every panel entry');

            return;
        }

        $viteConfig = $this->viteConfig();
        $unconfigured = array_values(array_filter(
            array_keys($missing),
            static fn (string $entry): bool => $viteConfig === null || ! str_contains($viteConfig, $entry),
        ));

        // The installer adds the entries to vite.config: a manifest without them is usually
        // a build from before AlfaKit was installed (starter kits ship one).
        yield $unconfigured === []
            ? DoctorCheck::error(
                'Frontend',
                'Assets',
                'the build is older than the panel entries (the Vite manifest has no '.implode(', ', array_keys($missing)).')',
                'npm run build (or npm run dev while developing)',
            )
            : DoctorCheck::error(
                'Frontend',
                'Assets',
                'the Vite manifest has no '.implode(', ', array_keys($missing)),
                'Add '.implode(', ', $unconfigured).' to laravel({ input: [...] }) in vite.config and run npm run build.',
            );
    }

    /** The contents of the app's Vite config, or null when there is none. */
    protected function viteConfig(): ?string
    {
        foreach (['ts', 'js', 'mts', 'mjs'] as $extension) {
            $path = $this->env->basePath("vite.config.{$extension}");

            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- SSR

    /**
     * @return iterable<DoctorCheck>
     */
    protected function ssrChecks(): iterable
    {
        $configured = config('inertia.ssr.bundle');
        $candidates = is_string($configured) && $configured !== ''
            ? [$configured]
            : [$this->env->basePath('bootstrap/ssr/ssr.js'), $this->env->basePath('bootstrap/ssr/ssr.mjs')];
        $bundle = Arr::first($candidates, static fn (string $path): bool => is_file($path));
        $entry = Arr::first(['tsx', 'ts', 'jsx', 'js'], fn (string $extension): bool => is_file($this->env->basePath("resources/js/alfakit/ssr.{$extension}")));

        // Inertia enables SSR by default and quietly renders in the browser without a
        // bundle, so SSR counts as wanted once an AlfaKit SSR entry or a bundle exists.
        if (! (bool) config('inertia.ssr.enabled', true) || ($bundle === null && $entry === null)) {
            yield DoctorCheck::skipped('SSR', 'Server-side rendering', 'not set up (php artisan alfakit:install --ssr)');

            return;
        }

        yield $bundle !== null
            ? DoctorCheck::ok('SSR', 'SSR bundle', Str::after($bundle, $this->env->basePath().DIRECTORY_SEPARATOR))
            : DoctorCheck::error('SSR', 'SSR bundle', 'resources/js/alfakit/ssr entry exists but no SSR bundle was built: pages render in the browser only', 'npm run build:ssr');

        if ($entry === null) {
            yield DoctorCheck::warning('SSR', 'SSR entry', 'an SSR bundle exists but resources/js/alfakit/ssr.* does not: panel pages may not render on the server', 'php artisan alfakit:install --ssr');
        }

        $url = (string) config('inertia.ssr.url', 'http://127.0.0.1:13714');

        yield DoctorCheck::ok('SSR', 'SSR server', "expected at {$url} (keep `php artisan inertia:start-ssr` running)");
    }

    // ---------------------------------------------------------------- Panels

    /** The app's stylesheet imports AlfaKit's standalone styles (`alfakit:install --standalone`). */
    protected function usesStandaloneComponents(): bool
    {
        $css = $this->env->basePath('resources/css/app.css');

        return is_file($css) && str_contains((string) file_get_contents($css), '/standalone.css');
    }

    /**
     * @return iterable<DoctorCheck>
     */
    protected function panelChecks(): iterable
    {
        $panels = alfakit()->getPanels();

        yield from $this->unregisteredProviders();

        if ($panels === []) {
            // `alfakit:install --standalone`: components in the app's own pages need no panel.
            yield $this->usesStandaloneComponents()
                ? DoctorCheck::skipped('Panels', 'Panels', 'no panel: standalone components only')
                : DoctorCheck::error('Panels', 'Panels', 'no panel is registered', 'php artisan make:alfakit-panel admin, then add the provider to bootstrap/providers.php');

            return;
        }

        yield DoctorCheck::ok('Panels', 'Panels', implode(', ', array_map(static fn (Panel $panel): string => $panel->getId(), $panels)));

        foreach ($panels as $panel) {
            yield from $this->discoveryCacheCheck($panel);
            yield from $this->resourceChecks($panel);
            yield from $this->componentChecks($panel, 'Pages', $panel->getPages(), Page::class);
            yield from $this->componentChecks($panel, 'Widgets', $panel->getWidgets(), Widget::class);
        }
    }

    /**
     * Panel providers in `app/Providers` that Laravel never loaded.
     *
     * @return iterable<DoctorCheck>
     */
    protected function unregisteredProviders(): iterable
    {
        $directory = app_path('Providers');

        if (! is_dir($directory)) {
            return;
        }

        foreach ((new Finder)->files()->in($directory)->name('*PanelProvider.php') as $file) {
            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'extends PanelProvider') || preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) !== 1) {
                continue;
            }

            $class = $namespace[1].'\\'.$file->getBasename('.php');

            if (class_exists($class) && is_subclass_of($class, PanelProvider::class) && app()->getProviders($class) === []) {
                yield DoctorCheck::error('Panels', class_basename($class), 'is not registered, so its panel does not exist', "Add {$class}::class to bootstrap/providers.php.");
            }
        }
    }

    /**
     * @return iterable<DoctorCheck>
     */
    protected function discoveryCacheCheck(Panel $panel): iterable
    {
        $file = rtrim((string) config('alfakit.cache_path'), '/')."/{$panel->getId()}.php";

        if (! is_file($file)) {
            return;
        }

        $cached = require $file;
        $current = $panel->getDiscoverableComponents();
        $stale = false;

        foreach ($current as $key => $classes) {
            $cachedClasses = (array) ($cached[$key] ?? []);

            foreach ($cachedClasses as $class) {
                if (! is_string($class) || ! class_exists($class)) {
                    yield DoctorCheck::error("Panel {$panel->getId()}", 'Discovery cache', "lists {$class}, which no longer exists", 'php artisan alfakit:optimize (or alfakit:optimize-clear)');

                    return;
                }
            }

            $stale = $stale || array_diff($classes, $cachedClasses) !== [];
        }

        yield $stale
            ? DoctorCheck::warning("Panel {$panel->getId()}", 'Discovery cache', 'stale: new resources, pages or widgets are not loaded', 'php artisan alfakit:optimize')
            : DoctorCheck::ok("Panel {$panel->getId()}", 'Discovery cache', 'up to date');
    }

    /**
     * @return iterable<DoctorCheck>
     */
    protected function resourceChecks(Panel $panel): iterable
    {
        $group = "Panel {$panel->getId()}";
        $resources = $panel->getResources();
        $broken = [];
        $withoutPolicy = [];

        foreach ($resources as $resource) {
            if (! class_exists($resource) || ! is_subclass_of($resource, Resource::class)) {
                $broken[] = "{$resource} (not a resource class)";

                continue;
            }

            try {
                $model = $resource::getModel();
            } catch (Throwable $exception) {
                $broken[] = class_basename($resource).": {$exception->getMessage()}";

                continue;
            }

            if (! class_exists($model) || ! is_subclass_of($model, Model::class)) {
                $broken[] = class_basename($resource)." (model {$model} not found)";

                continue;
            }

            if (! $this->isAuthorized($resource, $model)) {
                $withoutPolicy[] = class_basename($resource);
            }
        }

        if ($broken !== []) {
            yield DoctorCheck::error($group, 'Resources', implode('; ', $broken), 'Create the missing classes or remove them from the panel.');
        } else {
            yield DoctorCheck::ok($group, 'Resources', count($resources).' resolvable');
        }

        if ($withoutPolicy === []) {
            return;
        }

        yield $panel->hasStrictAuthorization()
            ? DoctorCheck::warning($group, 'Policies', 'no policy for '.implode(', ', $withoutPolicy).': strictAuthorization() denies every action', 'php artisan make:policy {Model}Policy --model={Model}')
            : DoctorCheck::warning($group, 'Policies', 'no policy for '.implode(', ', $withoutPolicy).': every signed-in user can do everything', 'Add policies (php artisan make:policy {Model}Policy --model={Model}) or ->strictAuthorization() on the panel.');
    }

    /**
     * Whether a resource is guarded: a policy, its own `can*()` overrides or an explicit
     * `$shouldSkipAuthorization`.
     *
     * @param  class-string<resource>  $resource
     */
    protected function isAuthorized(string $resource, string $model): bool
    {
        if (Gate::getPolicyFor($model) !== null) {
            return true;
        }

        $reflection = new ReflectionClass($resource);

        if ($reflection->hasProperty('shouldSkipAuthorization') && $reflection->getProperty('shouldSkipAuthorization')->getValue() === true) {
            return true;
        }

        foreach (['can', 'canViewAny'] as $method) {
            if ($reflection->getMethod($method)->getDeclaringClass()->getName() !== Resource::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<class-string>  $classes
     * @param  class-string  $baseClass
     * @return iterable<DoctorCheck>
     */
    protected function componentChecks(Panel $panel, string $label, array $classes, string $baseClass): iterable
    {
        if ($classes === []) {
            return;
        }

        $broken = array_values(array_filter($classes, static fn (string $class): bool => ! class_exists($class) || ! is_subclass_of($class, $baseClass)));

        yield $broken === []
            ? DoctorCheck::ok("Panel {$panel->getId()}", $label, count($classes).' resolvable')
            : DoctorCheck::error("Panel {$panel->getId()}", $label, 'not found: '.implode(', ', $broken), 'Create the classes or remove them from the panel.');
    }

    // ---------------------------------------------------------------- Translations

    /**
     * Published overrides (`lang/vendor/alfakit/{locale}`): keys English no longer has
     * (usually typos) and lines whose `:placeholders` differ from English.
     *
     * @return iterable<DoctorCheck>
     */
    protected function translationChecks(): iterable
    {
        $path = app()->langPath('vendor/alfakit');

        if (! is_dir($path)) {
            yield DoctorCheck::skipped('Translations', 'Overrides', 'none published');

            return;
        }

        $checker = new TranslationChecker(app('translator')->getLoader(), 'alfakit');
        $source = $checker->source();

        foreach ((new Finder)->directories()->in($path)->depth(0) as $directory) {
            $locale = $directory->getBasename();
            $extra = [];
            $placeholders = [];

            foreach ((new Finder)->files()->in($directory->getPathname())->name('*.php') as $file) {
                $lines = require $file->getPathname();
                $group = $file->getBasename('.php');

                foreach (Arr::dot(is_array($lines) ? $lines : []) as $key => $value) {
                    $dotted = "{$group}.{$key}";

                    if (! isset($source[$dotted])) {
                        $extra[] = $dotted;
                    } elseif (is_string($value) && TranslationChecker::placeholders($value) !== TranslationChecker::placeholders($source[$dotted])) {
                        $placeholders[] = $dotted;
                    }
                }
            }

            $missing = count($checker->check($locale)['missing']);

            yield match (true) {
                $placeholders !== [] => DoctorCheck::error('Translations', "Overrides ({$locale})", 'placeholders differ from English: '.self::summarize($placeholders), "Keep every :placeholder of the English line (php artisan alfakit:lang:check {$locale} -v)."),
                $extra !== [] => DoctorCheck::warning('Translations', "Overrides ({$locale})", 'keys that do not exist: '.self::summarize($extra), 'Remove them or fix their names (php artisan alfakit:lang:check '.$locale.' --strict -v).'),
                $missing > 0 => DoctorCheck::warning('Translations', "Overrides ({$locale})", "{$missing} keys fall back to English", "php artisan alfakit:lang:check {$locale} -v"),
                default => DoctorCheck::ok('Translations', "Overrides ({$locale})", 'in sync with English'),
            };
        }
    }

    // ---------------------------------------------------------------- Storage

    /**
     * Public local disks used by file uploads need `storage:link`.
     *
     * @return iterable<DoctorCheck>
     */
    protected function storageChecks(): iterable
    {
        $disks = [(string) config('alfakit.default_filesystem_disk', config('filesystems.default', 'public'))];

        foreach ($this->sources() as $contents) {
            if (preg_match_all('/->disk\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $contents, $matches) > 0) {
                array_push($disks, ...$matches[1]);
            }
        }

        $checked = false;

        foreach (array_unique($disks) as $disk) {
            $config = config("filesystems.disks.{$disk}");

            if (! is_array($config) || ($config['driver'] ?? null) !== 'local' || ($config['visibility'] ?? null) !== 'public') {
                continue;
            }

            $checked = true;
            $root = rtrim((string) ($config['root'] ?? ''), '/');
            $links = (array) config('filesystems.links', [$this->env->publicPath('storage') => storage_path('app/public')]);
            $link = array_search($root, array_map(static fn (mixed $target): string => rtrim((string) $target, '/'), $links), true);

            if ($link === false) {
                yield DoctorCheck::warning('Storage', "Disk {$disk}", 'public, but no entry in filesystems.links points at it: uploaded files have no URL', "Add public_path('…') => '{$root}' to filesystems.links and run php artisan storage:link.");

                continue;
            }

            yield is_link((string) $link) || is_dir((string) $link)
                ? DoctorCheck::ok('Storage', "Disk {$disk}", 'linked at '.Str::after((string) $link, $this->env->basePath().DIRECTORY_SEPARATOR))
                : DoctorCheck::error('Storage', "Disk {$disk}", 'storage:link has not been run: uploaded images return 404', 'php artisan storage:link');
        }

        if (! $checked) {
            yield DoctorCheck::skipped('Storage', 'Public disks', 'uploads use no public local disk');
        }
    }

    // ---------------------------------------------------------------- Queue

    /**
     * @return iterable<DoctorCheck>
     */
    protected function queueChecks(): iterable
    {
        $features = [];

        if ($this->sourcesContain(['ExportAction', 'ExportBulkAction'])) {
            $features[] = 'exports';
        }

        if ($this->sourcesContain(['ImportAction'])) {
            $features[] = 'imports';
        }

        foreach (alfakit()->getPanels() as $panel) {
            foreach (self::QUEUED_PLUGINS as $plugin) {
                if ($panel->hasPlugin($plugin)) {
                    $features[] = $plugin;
                }
            }
        }

        $features = array_values(array_unique($features));

        if ($features === []) {
            yield DoctorCheck::skipped('Queue', 'Queue', 'no queued features in use');

            return;
        }

        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $uses = implode(', ', $features);

        yield match (true) {
            $driver === 'sync' && $this->env->isProduction() => DoctorCheck::error('Queue', 'Queue', "{$uses} run on the sync driver in production: requests block until they finish", 'Set QUEUE_CONNECTION=database (or redis) and run php artisan queue:work.'),
            $driver === 'sync' => DoctorCheck::warning('Queue', 'Queue', "{$uses} run synchronously (sync driver)", 'Fine while developing; use database or redis with a worker in production.'),
            default => DoctorCheck::ok('Queue', 'Queue', "{$driver} for {$uses} (keep a queue:work process running)"),
        };
    }

    // ---------------------------------------------------------------- Broadcasting

    /**
     * @return iterable<DoctorCheck>
     */
    protected function broadcastingChecks(): iterable
    {
        $panels = array_filter(alfakit()->getPanels(), static fn (Panel $panel): bool => $panel->isRealtimeConfigured());

        if ($panels === []) {
            yield DoctorCheck::skipped('Broadcasting', 'Realtime', 'no panel uses ->realtime()');

            return;
        }

        $ids = implode(', ', array_map(static fn (Panel $panel): string => $panel->getId(), $panels));
        $connection = (string) config('broadcasting.default', 'null');
        $driver = config("broadcasting.connections.{$connection}.driver");

        if (in_array($connection, ['null', 'log', ''], true) || ! is_string($driver) || in_array($driver, ['null', 'log'], true)) {
            yield DoctorCheck::error('Broadcasting', 'Realtime', "panel {$ids} uses ->realtime() but broadcasting is not configured ({$connection})", 'php artisan alfakit:realtime (or php artisan install:broadcasting)');

            return;
        }

        yield DoctorCheck::ok('Broadcasting', 'Realtime', "{$driver} for panel {$ids}");

        $package = $this->env->readJson($this->env->basePath('package.json')) ?? [];

        if (! isset($package['dependencies']['laravel-echo']) && ! isset($package['devDependencies']['laravel-echo'])) {
            yield DoctorCheck::warning('Broadcasting', 'Laravel Echo', 'laravel-echo is not in package.json: the browser cannot listen', 'php artisan alfakit:realtime');
        }
    }

    // ---------------------------------------------------------------- Migrations

    /**
     * @return iterable<DoctorCheck>
     */
    protected function migrationChecks(): iterable
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            yield DoctorCheck::error('Database', 'Connection', $exception->getMessage(), 'Check the DB_* settings in .env.');

            return;
        }

        $panels = alfakit()->getPanels();
        $features = [];

        if ($this->usesRecordVersions($panels)) {
            $features['Record versions'] = ['alfakit_record_versions', 'alfakit-record-versions-migrations'];
        }

        if ($this->any($panels, static fn (Panel $panel): bool => $panel->hasTableSavedViews()) || $this->sourcesContain(['savedViews(', '->views('])) {
            $features['Saved table views'] = [(string) config('alfakit.tables.views_table', 'alfakit_table_views'), 'alfakit-table-views-migrations'];
        }

        if ($this->any($panels, static fn (Panel $panel): bool => $panel->hasCustomizableDashboards()) || $this->sourcesContain(['customizable('])) {
            $features['Customizable dashboards'] = [(string) config('alfakit.dashboards.layouts_table', 'alfakit_dashboard_layouts'), 'alfakit-dashboard-migrations'];
        }

        // Whole words: a page's own `getExportAction()` is not AlfaKit's exporter.
        if ($this->sourcesMatch('/\\b(Export|ExportBulk|Import)Action\\b/')) {
            $features['Imports and exports'] = ['exports', 'alfakit-import-export-migrations'];
        }

        foreach ($features as $feature => [$table, $tag]) {
            yield Schema::hasTable($table)
                ? DoctorCheck::ok('Database', $feature, "{$table} exists")
                : DoctorCheck::error('Database', $feature, "used, but the {$table} table is missing", "php artisan vendor:publish --tag={$tag} && php artisan migrate");
        }

        yield Schema::hasTable('alfakit_user_preferences')
            ? DoctorCheck::ok('Database', 'User preferences', 'alfakit_user_preferences exists')
            : DoctorCheck::warning('Database', 'User preferences', 'stored in the session, so they are lost on sign-out', 'php artisan vendor:publish --tag=alfakit-user-preferences-migrations && php artisan migrate');
    }

    /**
     * @param  array<Panel>  $panels
     */
    protected function usesRecordVersions(array $panels): bool
    {
        foreach ($panels as $panel) {
            foreach ($panel->getResources() as $resource) {
                try {
                    $model = $resource::getModel();
                } catch (Throwable) {
                    continue;
                }

                if (class_exists($model) && in_array(HasRecordVersions::class, class_uses_recursive($model), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<Panel>  $panels
     * @param  Closure(Panel): bool  $callback
     */
    protected function any(array $panels, Closure $callback): bool
    {
        foreach ($panels as $panel) {
            if ($callback($panel)) {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- Caches

    /**
     * @return iterable<DoctorCheck>
     */
    protected function cacheChecks(): iterable
    {
        $cached = array_keys(array_filter([
            'config' => $this->env->configurationIsCached(),
            'routes' => $this->env->routesAreCached(),
            'events' => $this->env->eventsAreCached(),
        ]));

        if ($this->env->isProduction()) {
            yield count($cached) === 3
                ? DoctorCheck::ok('Cache', 'Optimize', 'config, routes and events cached')
                : DoctorCheck::warning('Cache', 'Optimize', 'not cached: '.implode(', ', array_diff(['config', 'routes', 'events'], $cached)), 'php artisan optimize && php artisan alfakit:optimize');

            return;
        }

        yield $cached === []
            ? DoctorCheck::ok('Cache', 'Optimize', 'nothing cached (changes apply immediately)')
            : DoctorCheck::warning('Cache', 'Optimize', implode(', ', $cached).' cached while developing: .env and route changes are ignored', 'php artisan optimize:clear');
    }

    // ---------------------------------------------------------------- Helpers

    /**
     * Source code of every resource, page and widget class of every panel, read once.
     * Heuristic checks (exports used, disks used) look for method calls in it.
     *
     * @return array<class-string, string>
     */
    protected function sources(): array
    {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $this->sources = [];

        foreach (alfakit()->getPanels() as $panel) {
            foreach ([...$panel->getResources(), ...$panel->getPages(), ...$panel->getWidgets()] as $class) {
                if (isset($this->sources[$class]) || ! class_exists($class)) {
                    continue;
                }

                $classes = [$class];

                if (is_subclass_of($class, Resource::class)) {
                    // Resource pages and relation managers live next to the resource.
                    try {
                        array_push($classes, ...array_map(static fn (mixed $page): string => is_object($page) && property_exists($page, 'page') ? (string) $page->page : '', $class::getPages()), ...array_filter($class::getRelations(), 'is_string'));
                    } catch (Throwable) {
                        // A resource that cannot list its pages is reported by resourceChecks().
                    }
                }

                foreach ($classes as $source) {
                    if ($source !== '' && class_exists($source) && ($file = (new ReflectionClass($source))->getFileName()) !== false) {
                        $this->sources[$source] = (string) file_get_contents($file);
                    }
                }
            }
        }

        return $this->sources;
    }

    protected function sourcesMatch(string $pattern): bool
    {
        foreach ($this->sources() as $contents) {
            if (preg_match($pattern, $contents) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>  $needles
     */
    protected function sourcesContain(array $needles): bool
    {
        foreach ($this->sources() as $contents) {
            if (Str::contains($contents, $needles)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `v1.2.3`, `1.2.3.0` and `1.2.x-dev` to `1.2.3`; null for branches (`dev-main`).
     */
    public static function normalizeVersion(string $version): ?string
    {
        if (preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?/', trim($version), $match) !== 1) {
            return null;
        }

        return sprintf('%d.%d.%d', $match[1], $match[2], $match[3] ?? 0);
    }

    /**
     * Same major (and minor while the major is 0): releases of the PHP package and the
     * renderers share their schema contract that way.
     */
    public static function isCompatible(string $a, string $b): bool
    {
        $a = explode('.', self::normalizeVersion($a) ?? '');
        $b = explode('.', self::normalizeVersion($b) ?? '');

        if (count($a) < 2 || count($b) < 2 || $a[0] !== $b[0]) {
            return false;
        }

        return $a[0] !== '0' || $a[1] === $b[1];
    }

    protected static function constraintFor(string $version): string
    {
        [$major, $minor] = explode('.', self::normalizeVersion($version) ?? '0.0.0');

        return $major === '0' ? "~0.{$minor}.0" : "^{$major}.0.0";
    }

    /**
     * @param  array<string>  $keys
     */
    protected static function summarize(array $keys): string
    {
        $shown = implode(', ', array_slice($keys, 0, 3));

        return count($keys) > 3 ? $shown.' and '.(count($keys) - 3).' more' : $shown;
    }
}
