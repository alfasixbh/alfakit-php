<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\Doctor\Doctor;
use AlfaSix\Alfakit\Support\Doctor\DoctorCheck;
use AlfaSix\Alfakit\Support\Doctor\DoctorEnvironment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;

/*
 * `alfakit:doctor`: every check against a faked environment (versions, environment name,
 * caches) and a temporary application directory (package.json, node_modules, the Vite
 * manifest), the table / JSON output and the exit codes.
 */

class DoctorTestEnvironment extends DoctorEnvironment
{
    public string $root;

    public string $env = 'local';

    public string $php = '8.4.1';

    public string $laravel = '13.2.0';

    /** @var array<string, string|null> */
    public array $packages = ['inertiajs/inertia-laravel' => 'v3.5.0', 'alfasix/alfakit' => 'v0.1.2'];

    public bool $configCached = false;

    public function basePath(string $path = ''): string
    {
        return rtrim($this->root.'/'.$path, '/');
    }

    public function publicPath(string $path = ''): string
    {
        return rtrim($this->root.'/public/'.$path, '/');
    }

    public function environment(): string
    {
        return $this->env;
    }

    public function phpVersion(): string
    {
        return $this->php;
    }

    public function laravelVersion(): string
    {
        return $this->laravel;
    }

    public function composerVersion(string $package): ?string
    {
        return $this->packages[$package] ?? null;
    }

    public function configurationIsCached(): bool
    {
        return $this->configCached;
    }

    public function routesAreCached(): bool
    {
        return $this->configCached;
    }

    public function eventsAreCached(): bool
    {
        return $this->configCached;
    }
}

class DoctorTestPostPolicy
{
    public function viewAny(): bool
    {
        return true;
    }
}

/**
 * @param  array<string, mixed>  $files  relative path => contents (arrays are JSON encoded)
 */
function doctorApp(DoctorTestEnvironment $env, array $files): void
{
    foreach ($files as $path => $contents) {
        $full = $env->root.'/'.$path;
        (new Filesystem)->ensureDirectoryExists(dirname($full));
        file_put_contents($full, is_array($contents) ? json_encode($contents) : $contents);
    }
}

/**
 * @return array<string, DoctorCheck>
 */
function doctorChecks(): array
{
    $checks = [];

    foreach (app(Doctor::class)->run() as $check) {
        $checks["{$check->group} › {$check->label}"] = $check;
    }

    return $checks;
}

function healthyApp(DoctorTestEnvironment $env): void
{
    $entries = [];

    foreach (alfakit()->getPanels() as $panel) {
        foreach ($panel->getViteEntries() as $entry) {
            $entries[$entry] = ['file' => 'assets/app.js'];
        }
    }

    doctorApp($env, [
        'package.json' => ['dependencies' => ['@alfakit/react' => '^0.1.0', '@alfakit/core' => '^0.1.0']],
        'node_modules/@alfakit/react/package.json' => ['version' => '0.1.4'],
        'node_modules/@alfakit/core/package.json' => ['version' => '0.1.4'],
        'public/build/manifest.json' => $entries,
        'public/storage/.gitignore' => '',
    ]);

    config(['filesystems.links' => [$env->publicPath('storage') => storage_path('app/public')]]);
}

beforeEach(function (): void {
    $this->env = new DoctorTestEnvironment;
    $this->env->root = sys_get_temp_dir().'/alfakit-doctor-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->env->root);
    app()->instance(DoctorEnvironment::class, $this->env);
    Doctor::flushExtensions();
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->env->root);
    Doctor::flushExtensions();
});

it('passes a healthy installation', function (): void {
    healthyApp($this->env);

    $checks = doctorChecks();

    expect($checks['Environment › PHP']->status->value)->toBe('ok')
        ->and($checks['Environment › Inertia']->status->value)->toBe('ok')
        ->and($checks['Environment › APP_KEY']->status->value)->toBe('ok')
        ->and($checks['Frontend › Renderer']->message)->toContain('@alfakit/react 0.1.4 matches alfasix/alfakit v0.1.2')
        ->and($checks['Frontend › Assets']->status->value)->toBe('ok')
        ->and($checks['Panels › Panels']->status->value)->toBe('ok')
        ->and($checks['SSR › Server-side rendering']->status->value)->toBe('skipped');
});

it('reports outdated PHP, Laravel and Inertia', function (): void {
    $this->env->php = '8.2.10';
    $this->env->laravel = '12.9.0';
    $this->env->packages['inertiajs/inertia-laravel'] = 'v2.0.1';

    $checks = doctorChecks();

    expect($checks['Environment › PHP']->status->value)->toBe('error')
        ->and($checks['Environment › Laravel']->status->value)->toBe('error')
        ->and($checks['Environment › Inertia']->fix)->toContain('inertia-laravel:^3.5');
});

it('explains missing renderer packages, version mismatches and missing builds', function (): void {
    expect(doctorChecks()['Frontend › package.json']->fix)->toBe('php artisan alfakit:install');

    doctorApp($this->env, ['package.json' => ['dependencies' => ['@alfakit/vue' => '^0.2.0']]]);
    expect(doctorChecks()['Frontend › Renderer']->message)->toContain('not installed');

    doctorApp($this->env, ['node_modules/@alfakit/vue/package.json' => ['version' => '0.2.0']]);
    $checks = doctorChecks();

    expect($checks['Frontend › Renderer']->status->value)->toBe('error')
        ->and($checks['Frontend › Renderer']->fix)->toBe('npm install @alfakit/vue@~0.1.0')
        ->and($checks['Frontend › Assets']->message)->toContain('manifest.json not found');

    doctorApp($this->env, ['public/build/manifest.json' => ['resources/css/other.css' => []]]);
    expect(doctorChecks()['Frontend › Assets']->message)->toContain('resources/js/alfakit/app.tsx')
        ->and(doctorChecks()['Frontend › Assets']->fix)->toContain('to laravel({ input');

    // The entries are configured but the build predates them (a starter kit's own build).
    doctorApp($this->env, ['vite.config.ts' => "laravel({ input: ['resources/css/alfakit.css', 'resources/js/alfakit/app.tsx'] })"]);
    expect(doctorChecks()['Frontend › Assets']->message)->toContain('the build is older than the panel entries')
        ->and(doctorChecks()['Frontend › Assets']->fix)->toBe('npm run build (or npm run dev while developing)');

    doctorApp($this->env, ['public/hot' => 'http://localhost:5173']);
    expect(doctorChecks()['Frontend › Assets']->message)->toContain('dev server');
});

it('reads versions from lock files', function (): void {
    doctorApp($this->env, [
        'package-lock.json' => ['packages' => ['node_modules/@alfakit/react' => ['version' => '0.1.9']]],
    ]);

    expect(app(Doctor::class)->installedNpmVersion('@alfakit/react'))->toBe('0.1.9');

    doctorApp($this->env, ['pnpm-lock.yaml' => "importers:\n  .:\n    dependencies:\n      '@alfakit/svelte':\n        specifier: ^0.1.0\n        version: 0.1.3(svelte@5.0.0)\n"]);

    expect(app(Doctor::class)->installedNpmVersion('@alfakit/svelte'))->toBe('0.1.3')
        ->and(Doctor::isCompatible('v0.1.2', '0.1.9'))->toBeTrue()
        ->and(Doctor::isCompatible('0.1.2', '0.2.0'))->toBeFalse()
        ->and(Doctor::isCompatible('1.2.0', '1.9.3'))->toBeTrue()
        ->and(Doctor::normalizeVersion('dev-main'))->toBeNull();
});

it('asks for storage:link when uploads use a public disk', function (): void {
    config(['filesystems.links' => [$this->env->publicPath('storage') => storage_path('app/public')]]);

    $check = doctorChecks()['Storage › Disk public'];

    expect($check->status->value)->toBe('error')
        ->and($check->fix)->toBe('php artisan storage:link');

    config(['filesystems.links' => []]);

    expect(doctorChecks()['Storage › Disk public']->status->value)->toBe('warning');
});

it('checks the SSR bundle when SSR is on', function (): void {
    config(['inertia.ssr.enabled' => true, 'inertia.ssr.bundle' => null]);

    expect(doctorChecks()['SSR › Server-side rendering']->status->value)->toBe('skipped');

    doctorApp($this->env, ['resources/js/alfakit/ssr.tsx' => '']);

    expect(doctorChecks()['SSR › SSR bundle']->status->value)->toBe('error');

    doctorApp($this->env, ['bootstrap/ssr/ssr.js' => '']);

    $checks = doctorChecks();

    expect($checks['SSR › SSR bundle']->status->value)->toBe('ok')
        ->and($checks)->not->toHaveKey('SSR › SSR entry');
});

it('flags debug mode in production, a missing key, missing caches and the sync queue', function (): void {
    $this->env->env = 'production';
    config(['app.debug' => true, 'app.key' => '']);

    $checks = doctorChecks();

    expect($checks['Environment › Debug mode']->status->value)->toBe('error')
        ->and($checks['Environment › APP_KEY']->fix)->toBe('php artisan key:generate')
        ->and($checks['Cache › Optimize']->status->value)->toBe('warning');

    $this->env->env = 'local';
    $this->env->configCached = true;

    expect(doctorChecks()['Cache › Optimize']->fix)->toBe('php artisan optimize:clear');
});

it('warns about resources without policies', function (): void {
    $checks = doctorChecks();
    $policies = collect($checks)->first(fn (DoctorCheck $check): bool => $check->label === 'Policies' && str_contains($check->message, 'PostResource'));

    expect($policies)->not->toBeNull()
        ->and($policies->status->value)->toBe('warning');

    Gate::policy(Post::class, DoctorTestPostPolicy::class);

    $after = collect(doctorChecks())->first(fn (DoctorCheck $check): bool => $check->label === 'Policies' && str_contains($check->message, 'PostResource,'));

    expect($after === null || ! str_contains($after->message, ' PostResource,'))->toBeTrue();
});

it('requires broadcasting for realtime panels', function (): void {
    alfakit()->getPanel('admin')->realtime();
    config(['broadcasting.default' => 'null']);

    expect(doctorChecks()['Broadcasting › Realtime']->status->value)->toBe('error');

    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb' => ['driver' => 'reverb']]);
    $checks = doctorChecks();

    expect($checks['Broadcasting › Realtime']->status->value)->toBe('ok')
        ->and($checks['Broadcasting › Laravel Echo']->status->value)->toBe('warning');
})->after(fn () => alfakit()->getPanel('admin')->realtime(false));

it('reports missing tables of opted-in features', function (): void {
    alfakit()->getPanel('admin')->customizableDashboards();

    $checks = doctorChecks();

    expect($checks['Database › Customizable dashboards']->status->value)->toBe('error')
        ->and($checks['Database › Customizable dashboards']->fix)->toContain('--tag=alfakit-dashboard-migrations');
})->after(fn () => alfakit()->getPanel('admin')->customizableDashboards(false));

it('checks published translation overrides', function (): void {
    $path = app()->langPath('vendor/alfakit/fr');
    (new Filesystem)->ensureDirectoryExists($path);
    file_put_contents("{$path}/ui.php", "<?php return ['no_such_key' => 'x'];");

    try {
        expect(doctorChecks()['Translations › Overrides (fr)']->status->value)->toBe('warning');
    } finally {
        (new Filesystem)->deleteDirectory(app()->langPath('vendor/alfakit'));
    }
});

it('runs extension checks and survives failing ones', function (): void {
    Doctor::extend(fn (): array => [DoctorCheck::ok('Billing', 'Stripe', 'configured')]);
    Doctor::extend(function (): array {
        throw new RuntimeException('boom');
    });

    $checks = doctorChecks();

    expect($checks['Billing › Stripe']->message)->toBe('configured')
        ->and($checks['Doctor › Check failed']->message)->toBe('boom');
});

it('prints a table, JSON for CI and fails on errors', function (): void {
    healthyApp($this->env);

    $errors = array_filter(doctorChecks(), fn (DoctorCheck $check): bool => $check->status->value === 'error');

    expect(array_map(fn (DoctorCheck $check): string => $check->message, $errors))->toBe([]);

    $this->artisan('alfakit:doctor')
        ->expectsOutputToContain('Environment')
        ->assertExitCode(0);

    // Warnings (no policy, user preferences table) fail only with --strict.
    $this->artisan('alfakit:doctor', ['--strict' => true])->assertExitCode(1);

    config(['app.key' => '']);

    $this->artisan('alfakit:doctor')->expectsOutputToContain('How to fix')->assertExitCode(1);

    $this->artisan('alfakit:doctor', ['--json' => true])
        ->expectsOutputToContain('"passed": false')
        ->assertExitCode(1);
});
