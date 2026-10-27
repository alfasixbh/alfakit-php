<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\UpgradeCommand;
use AlfaSix\Alfakit\Support\Deprecations;
use AlfaSix\Alfakit\Support\PluginCompatibility;
use AlfaSix\Alfakit\Support\SchemaContract;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/*
 * Upgrade stability: the schema contract version shared with every page, deprecation
 * warnings for legacy command aliases, and the plugin compatibility report of
 * alfakit:upgrade. Command tests run in a temporary application directory.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-upgrade-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/vendor/composer');
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/public');

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    $this->app->usePublicPath($this->sandbox.'/public');
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);

    Deprecations::flush();
});

afterEach(function (): void {
    Deprecations::flush();
    $this->app->setBasePath($this->originalBasePath);
    $this->app->usePublicPath($this->originalBasePath.'/public');
    (new Filesystem)->deleteDirectory($this->sandbox);
});

/**
 * Register an upgrade command whose report sees Alfakit at `$version`.
 */
function upgradeCommandAs(string $version): void
{
    app()->bind(UpgradeCommand::class, fn (): UpgradeCommand => new class($version) extends UpgradeCommand
    {
        public function __construct(private string $alfakitVersion)
        {
            parent::__construct();
        }

        protected function compatibility(): PluginCompatibility
        {
            return new PluginCompatibility($this->alfakitVersion, base_path('vendor/composer/installed.json'), base_path('package.json'));
        }
    });
}

/**
 * @param  array<int, array<string, mixed>>  $packages
 */
function installedPackages(array $packages): void
{
    file_put_contents(base_path('vendor/composer/installed.json'), json_encode(['dev' => true, 'packages' => $packages]));
}

// ------------------------------------------------------------------ Schema contract

it('shares the schema contract version with every page', function (): void {
    $this->app->setBasePath($this->originalBasePath);

    $props = inertiaProps(inertiaGet($this, '/admin/login'));

    expect($props['alfakit']['schemaContract'])->toBe(SchemaContract::VERSION)
        ->and(SchemaContract::isCompatible($props['alfakit']['schemaContract']))->toBeTrue();
});

// -------------------------------------------------------------------- Deprecations

it('warns once when a command is invoked through its legacy alias', function (): void {
    Log::spy();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/resources/css');

    $this->artisan('alfakit:make-theme', ['name' => 'legacy-one', '--extends' => 'alfakit'])
        ->expectsOutputToContain('alfakit:make-theme command name is deprecated; use php artisan make:alfakit-theme instead')
        ->assertSuccessful();

    $this->artisan('alfakit:make-theme', ['name' => 'legacy-two', '--extends' => 'alfakit'])->assertSuccessful();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'artisan alfakit:make-theme is deprecated since Alfakit 0.1'));

    expect(Deprecations::all())->toHaveCount(1)
        ->and(Deprecations::all()[0]['subject'])->toBe('artisan alfakit:make-theme');
});

it('does not warn when a command is invoked by its current name', function (): void {
    Log::spy();
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/resources/css');

    $this->artisan('make:alfakit-theme', ['name' => 'current', '--extends' => 'alfakit'])
        ->doesntExpectOutputToContain('deprecated')
        ->assertSuccessful();

    Log::shouldNotHaveReceived('warning');
    expect(Deprecations::all())->toBe([]);
});

// --------------------------------------------------------- Plugin compatibility report

it('reports plugin and renderer compatibility without failing', function (): void {
    upgradeCommandAs('1.4.0');
    installedPackages([
        ['name' => 'acme/blog', 'version' => '1.2.0', 'extra' => ['alfakit' => ['version' => '^1.0', 'frontend' => '@acme/blog']]],
        ['name' => 'acme/shop', 'version' => '2.0.0', 'extra' => ['alfakit' => ['version' => '^2.0']]],
        ['name' => 'acme/mystery', 'version' => '1.0.0', 'extra' => ['alfakit' => []]],
    ]);
    file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['@alfakit/react' => '^1.1.0', '@acme/blog' => '^1.2.0']]));

    // Each expectation targets a distinct output line (an output line satisfies one expectation).
    $this->artisan('alfakit:upgrade')
        ->expectsOutputToContain('Compatibility with alfasix/alfakit 1.4.0')
        ->expectsOutputToContain('acme/blog 1.2.0: compatible')
        ->expectsOutputToContain('acme/shop 2.0.0: incompatible')
        ->expectsOutputToContain('requires alfasix/alfakit ^2.0, but 1.4.0 is installed')
        ->expectsOutputToContain('acme/mystery 1.0.0: unknown')
        ->expectsOutputToContain('@alfakit/react is ^1.1.0 in package.json: compatible')
        ->assertSuccessful();
});

it('fails on incompatible packages only with --strict', function (): void {
    upgradeCommandAs('1.4.0');
    installedPackages([
        ['name' => 'acme/shop', 'version' => '2.0.0', 'extra' => ['alfakit' => ['version' => '^2.0']]],
    ]);

    $this->artisan('alfakit:upgrade', ['--strict' => true])
        ->expectsOutputToContain('Incompatible packages were found (--strict)')
        ->assertFailed();
});

it('passes --strict when everything is compatible or unknown', function (): void {
    upgradeCommandAs('1.4.0');
    installedPackages([
        ['name' => 'acme/blog', 'version' => '1.2.0', 'extra' => ['alfakit' => ['version' => '^1.0']]],
        ['name' => 'acme/mystery', 'version' => '1.0.0', 'extra' => ['alfakit' => []]],
    ]);
    file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['@alfakit/react' => '^1.0.0']]));

    $this->artisan('alfakit:upgrade', ['--strict' => true])->assertSuccessful();
});

it('flags an @alfakit npm package from another major', function (): void {
    upgradeCommandAs('2.0.0');
    file_put_contents(base_path('package.json'), json_encode(['dependencies' => ['@alfakit/vue' => '^1.3.0']]));

    $this->artisan('alfakit:upgrade', ['--strict' => true])
        ->expectsOutputToContain('@alfakit/vue is ^1.3.0 in package.json')
        ->expectsOutputToContain('npm install @alfakit/vue@^2.0')
        ->assertFailed();
});

it('runs without any plugin or package.json', function (): void {
    $this->artisan('alfakit:upgrade', ['--strict' => true])
        ->doesntExpectOutputToContain('Compatibility')
        ->assertSuccessful();
});

// ------------------------------------------------------------------ Plugin generator

it('declares compatibility in generated plugins', function (): void {
    $this->artisan('make:alfakit-plugin', ['name' => 'Blog', '--vendor' => 'acme', '--frontend' => 'vue'])->assertSuccessful();

    $composer = json_decode((string) file_get_contents(base_path('packages/blog/composer.json')), true);

    expect($composer['extra']['alfakit']['version'])->toBeString()->not->toBe('')
        ->and($composer['require']['alfasix/alfakit'])->toBe($composer['extra']['alfakit']['version'])
        ->and($composer['extra']['alfakit']['frontend'])->toBe('@acme/blog');

    $this->artisan('make:alfakit-plugin', ['name' => 'Audit', '--vendor' => 'acme', '--frontend' => 'none'])->assertSuccessful();

    $composer = json_decode((string) file_get_contents(base_path('packages/audit/composer.json')), true);

    expect($composer['extra']['alfakit'])->toHaveKey('frontend')
        ->and($composer['extra']['alfakit']['frontend'])->toBeNull();
});

// --------------------------------------------------------- New migrations

it('lists and publishes only the new migrations of AlfaKit packages already set up', function (): void {
    $files = new Filesystem;
    $source = sys_get_temp_dir().'/alfakit-upgrade-'.uniqid().'/alfakit-shop/database/migrations';
    $other = dirname($source, 3).'/alfakit-unused/database/migrations';
    $files->ensureDirectoryExists($source);
    $files->ensureDirectoryExists($other);
    file_put_contents($source.'/2026_01_01_000000_create_shop_tables.php', '<?php');
    file_put_contents($source.'/2026_10_27_000000_create_shop_coupons_table.php', '<?php');
    file_put_contents($other.'/2026_01_01_000000_create_unused_tables.php', '<?php');

    $paths = new ReflectionProperty(Illuminate\Support\ServiceProvider::class, 'publishableMigrationPaths');
    $before = $paths->getValue();
    $paths->setValue(null, [...$before, $source, $other]);

    $migrations = database_path('migrations');
    $files->ensureDirectoryExists($migrations);
    file_put_contents($migrations.'/2026_03_04_101010_create_shop_tables.php', '<?php');

    try {
        $this->artisan('alfakit:upgrade')
            ->expectsOutputToContain('New migrations: create_shop_coupons_table.php')
            ->assertSuccessful();

        $this->artisan('alfakit:upgrade', ['--migrations' => true])->assertSuccessful();

        $published = array_map(basename(...), glob($migrations.'/*_create_shop_*.php') ?: []);

        expect($published)->toHaveCount(2)
            ->and(collect($published)->contains(fn (string $file): bool => str_ends_with($file, '_create_shop_coupons_table.php')))->toBeTrue()
            ->and(glob($migrations.'/*_create_unused_tables.php'))->toBe([]);

        $this->artisan('alfakit:upgrade')->doesntExpectOutputToContain('New migrations')->assertSuccessful();
    } finally {
        $paths->setValue(null, $before);
        foreach (glob($migrations.'/*_create_shop_*.php') ?: [] as $file) {
            unlink($file);
        }
        $files->deleteDirectory(dirname($source, 3));
    }
});
