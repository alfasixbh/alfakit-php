<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Panel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\Process\Process;

/*
 * `alfakit:migrate-from-filament` on a copy of the fixture Filament app in
 * tests/Fixtures/FilamentApp: a v4 resource (form, infolist, table, relation manager), a v3
 * resource (Form/Infolist signatures, Filament\Tables\Actions), a Livewire custom page,
 * widgets, a panel provider with plugins/render hooks/theme, a Livewire component, a Blade
 * view, a theme, plugins in composer.json and a Livewire test.
 */

const FIXTURE_APP = __DIR__.'/../Fixtures/FilamentApp';

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->sandbox = sys_get_temp_dir().'/alfakit-migrate-'.uniqid();
    $this->files->copyDirectory(FIXTURE_APP, $this->sandbox);
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->sandbox);
});

/**
 * @param  array<string, mixed>  $options
 */
function runMigration(mixed $test, array $options = []): Illuminate\Testing\PendingCommand
{
    return $test->artisan('alfakit:migrate-from-filament', [
        '--path' => $test->sandbox,
        '--keep-filament' => true,
        '--no-npm' => true,
        '--rector' => 'none',
        ...$options,
    ]);
}

/**
 * @return array<string, string> relative path → md5, ignoring the report and backups
 */
function snapshotFiles(string $root): array
{
    $hashes = [];

    foreach ((new Filesystem)->allFiles($root, true) as $file) {
        $relative = $file->getRelativePathname();

        if ($relative === 'alfakit-migration-report.md' || str_starts_with($relative, 'storage/alfakit-migration-backup-')) {
            continue;
        }

        $hashes[$relative] = md5($file->getContents());
    }

    ksort($hashes);

    return $hashes;
}

/**
 * @return array<int, string>
 */
function rewrittenPhpFiles(string $root): array
{
    $files = [];

    foreach (['app/Alfakit', 'app/Providers/Alfakit', 'app/Models', 'app/Livewire', 'tests'] as $directory) {
        foreach ((new Filesystem)->allFiles($root.'/'.$directory) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

/**
 * The code of a migrated file without its `// TODO(alfakit-migration): …` lines, which quote
 * what was removed.
 */
function withoutMigrationTodos(string $code): string
{
    return (string) preg_replace('/^\s*\/\/ TODO\(alfakit-migration\).*\R/m', '', $code);
}

function assertMigratedFixture(mixed $test, string $root): void
{
    $files = new Filesystem;

    // Directories and namespaces moved.
    expect($files->isDirectory($root.'/app/Filament'))->toBeFalse()
        ->and($files->isDirectory($root.'/app/Providers/Filament'))->toBeFalse()
        ->and($files->exists($root.'/app/Alfakit/Resources/PostResource.php'))->toBeTrue()
        ->and($files->exists($root.'/app/Alfakit/Resources/PostResource/RelationManagers/CommentsRelationManager.php'))->toBeTrue()
        ->and($files->exists($root.'/app/Providers/Alfakit/AdminPanelProvider.php'))->toBeTrue();

    $resource = withoutMigrationTodos($files->get($root.'/app/Alfakit/Resources/PostResource.php'));

    expect($resource)
        ->toContain('namespace App\\Alfakit\\Resources;')
        ->toMatch('/use App\\\\Alfakit\\\\Resources\\\\PostResource\\\\Pages(\\\\ListPosts)?;/')
        ->toContain('use AlfaSix\\Alfakit\\Resources\\Resource;')
        ->toContain('use AlfaSix\\Alfakit\\Schemas\\Schema;')
        ->toContain("protected static ?string \$navigationIcon = 'file-text';")
        ->toContain("protected static ?string \$navigationGroup = 'Blog';")
        ->toContain('->headerActions([')
        ->toContain('->bulkActions([')
        ->toContain('DeleteBulkAction::make()')
        ->toContain('RestoreBulkAction::make()')
        ->toContain('@see \\AlfaSix\\Alfakit\\Resources\\Resource')
        ->not->toContain('BulkActionGroup')
        ->not->toContain('toolbarActions')
        ->not->toContain('Heroicon')
        ->not->toContain('BackedEnum')
        ->not->toContain('Filament\\');

    $category = withoutMigrationTodos($files->get($root.'/app/Alfakit/Resources/CategoryResource.php'));

    expect($category)
        ->toContain('public static function form(Schema $form): Schema')
        ->toContain('public static function infolist(Schema $infolist): Schema')
        ->toContain('use AlfaSix\\Alfakit\\Schemas\\Components\\Section;')
        ->toContain('use AlfaSix\\Alfakit\\Actions\\EditAction;')
        ->toContain('use AlfaSix\\Alfakit\\Support\\Enums\\Width;')
        ->toContain('->modalWidth(Width::Large)')
        ->toContain("protected static ?string \$navigationIcon = 'tag';")
        ->toContain('->live(onBlur: true)')
        ->not->toContain('extraAlpineAttributes')
        ->not->toContain('BulkActionGroup')
        ->not->toContain('Filament\\');
    expect(substr_count($category, 'use AlfaSix\\Alfakit\\Schemas\\Schema;'))->toBe(1);

    $settings = withoutMigrationTodos($files->get($root.'/app/Alfakit/Pages/Settings.php'));

    expect($settings)
        ->toContain('public function mount(mixed ...$parameters): void')
        ->toContain('$this->formData = [')
        ->toContain('$this->getForm()->getState()')
        ->toContain("protected static ?string \$navigationIcon = 'settings';")
        ->not->toContain('$view')
        ->not->toContain('public ?array $data')
        ->not->toContain('statePath')
        ->not->toContain('Filament\\');

    $chart = withoutMigrationTodos($files->get($root.'/app/Alfakit/Widgets/PostsChart.php'));

    expect($chart)
        ->toContain("protected ?string \$heading = 'Posts per month';")
        // Filament's `getType()` is the chart type; AlfaKit's is the schema type.
        ->toContain('protected function getChartType(): string')
        ->not->toContain('function getType(')
        ->toContain('use AlfaSix\\Alfakit\\Widgets\\ChartWidget;');

    $provider = withoutMigrationTodos($files->get($root.'/app/Providers/Alfakit/AdminPanelProvider.php'));

    expect($provider)
        ->toContain('namespace App\\Providers\\Alfakit;')
        ->toContain('use AlfaSix\\Alfakit\\PanelProvider;')
        ->toContain("->middleware(['web'])")
        ->toContain("'primary' => '#d97706'")
        ->toContain("'danger' => '#b91c1c'")
        ->toContain('->maxContentWidth(Width::Full)')
        ->toContain("->discoverResources(in: app_path('Alfakit/Resources'), for: 'App\\Alfakit\\Resources')")
        ->toContain("NavigationGroup::make('Blog')->icon('newspaper')")
        ->toContain('->databaseNotifications()')
        ->not->toContain('authMiddleware')
        ->not->toContain('AccountWidget')
        ->not->toContain('FilamentInfoWidget')
        ->not->toContain('plugins')
        ->not->toContain('renderHook')
        ->toMatch('/->slot\((\\\\AlfaSix\\\\Alfakit\\\\Theming\\\\)?Slot::BODY_START, fn/')
        ->not->toContain('viteTheme')
        ->not->toContain('databaseNotificationsPolling')
        ->not->toContain('EncryptCookies')
        ->not->toContain('Filament\\');

    expect($files->get($root.'/app/Models/User.php'))
        ->toContain('use AlfaSix\\Alfakit\\Contracts\\CanAccessPanel;')
        ->toContain('use AlfaSix\\Alfakit\\Panel;')
        ->toContain('implements CanAccessPanel');

    // The provider is registered, config/filament.php is gone, composer scripts point at AlfaKit.
    expect($files->get($root.'/bootstrap/providers.php'))
        ->toContain('App\\Providers\\Alfakit\\AdminPanelProvider')
        ->not->toContain('Filament\\');
    expect($files->exists($root.'/config/filament.php'))->toBeFalse();
    expect($files->get($root.'/composer.json'))
        ->toContain('alfakit:upgrade')
        ->toContain('alfakit:assets')
        ->not->toContain('filament:')
        ->toContain('"filament/filament"');

    // Every AlfaKit class the rewritten code imports exists, and the code parses.
    foreach (rewrittenPhpFiles($root) as $file) {
        $contents = $files->get($file);

        preg_match_all('/^use (AlfaSix\\\\Alfakit\\\\[A-Za-z0-9_\\\\]+)(?: as \w+)?;/m', $contents, $matches);

        foreach ($matches[1] as $class) {
            expect(class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class))->toBeTrue("{$class} imported by {$file} does not exist");
        }

        $lint = new Process([PHP_BINARY, '-l', $file]);
        $lint->run();
        expect($lint->isSuccessful())->toBeTrue($lint->getOutput().$lint->getErrorOutput());

        if (! str_contains($file, '/app/Livewire/') && ! str_contains($file, '/tests/')) {
            expect($contents)->not->toContain('Filament\\');
        }
    }

    // Frontend installed without a second panel provider.
    expect($files->exists($root.'/resources/js/alfakit/app.tsx'))->toBeTrue()
        ->and($files->exists($root.'/resources/css/alfakit.css'))->toBeTrue()
        ->and($files->exists($root.'/config/alfakit.php'))->toBeTrue()
        ->and($files->get($root.'/vite.config.js'))->toContain('resources/js/alfakit/app.tsx')
        ->and(array_map(static fn ($f) => $f->getFilename(), $files->files($root.'/app/Providers/Alfakit')))->toBe(['AdminPanelProvider.php']);

    // No git: the touched files were backed up first.
    $backups = array_filter($files->directories($root.'/storage'), static fn (string $dir): bool => str_contains($dir, 'alfakit-migration-backup-'));
    expect($backups)->toHaveCount(1);
    expect($files->exists(reset($backups).'/app/Filament/Resources/PostResource.php'))->toBeTrue();
}

it('maps every Filament class to an AlfaKit class that exists', function (): void {
    foreach (FilamentClassMap::classes() as $filament => $alfakit) {
        expect(str_starts_with($filament, 'Filament\\'))->toBeTrue($filament);
        expect(class_exists($alfakit) || interface_exists($alfakit) || trait_exists($alfakit) || enum_exists($alfakit))->toBeTrue("{$filament} → {$alfakit} does not exist");
    }

    expect(array_intersect_key(FilamentClassMap::classes(), FilamentClassMap::unsupported()))->toBe([]);
    expect(count(FilamentClassMap::classes()))->toBeGreaterThan(200);

    foreach (FilamentClassMap::removedMethods() as $method => $reason) {
        expect($reason)->toContain('removed');
    }

    expect(FilamentClassMap::icon('heroicon-o-users'))->toBe(['users', true])
        ->and(FilamentClassMap::icon('OutlinedCog6Tooth'))->toBe(['settings', true])
        ->and(FilamentClassMap::icon('heroicon-m-arrow-trending-up'))->toBe(['arrow-trending-up', false]);
});

it('migrates the fixture Filament app with the built-in rewriter and reports what needs attention', function (): void {
    runMigration($this)->assertSuccessful();

    assertMigratedFixture($this, $this->sandbox);

    $report = $this->files->get($this->sandbox.'/alfakit-migration-report.md');

    expect($report)
        ->toContain('Detected Filament v4.1.0')
        ->toContain('Moved `app/Filament` to `app/Alfakit`')
        ->toContain('Registered `App\\Providers\\Alfakit\\AdminPanelProvider`')
        // Livewire component, Blade views, Alpine attributes.
        ->toMatch('/`app\/Livewire\/ContactForm\.php:\d+` — Livewire component/')
        ->toMatch('/`app\/Livewire\/ContactForm\.php:\d+` — `Filament\\\\Forms\\\\Contracts\\\\HasForms`/')
        ->toContain('`resources/views/filament/pages/settings.blade.php:1` — Blade view `filament.pages.settings` uses `<x-filament-panels::page`')
        ->toMatch('/`app\/Alfakit\/Pages\/Settings\.php:\d+` — Custom Blade view `filament\.pages\.settings`/')
        ->toMatch('/`app\/Alfakit\/Resources\/CategoryResource\.php:\d+` — `->extraAlpineAttributes\(\)`/')
        ->toMatch('/`app\/Alfakit\/Resources\/CategoryResource\.php:\d+` — `->lazy\(\)` became `->live\(onBlur: true\)`/')
        // Panel: render hook → slots, theme, plugins, widgets.
        ->toMatch('/`app\/Providers\/Alfakit\/AdminPanelProvider\.php:\d+` — `->renderHook\(\)` became `->slot\(\)`/')
        ->toMatch('/`app\/Providers\/Alfakit\/AdminPanelProvider\.php:\d+` — `->viteTheme\(/')
        ->toContain('make:alfakit-theme')
        ->toMatch('/`app\/Providers\/Alfakit\/AdminPanelProvider\.php:\d+` — Plugin `BezhanSalleh\\\\FilamentShield\\\\FilamentShieldPlugin` removed/')
        ->toContain('`Filament\\Widgets\\AccountWidget` removed from the panel')
        ->toMatch('/`app\/Providers\/Alfakit\/AdminPanelProvider\.php:\d+` — `->databaseNotificationsPolling\(\)`/')
        // Plugins from composer.json.
        ->toMatch('/`composer\.json:\d+` — `bezhansalleh\/filament-shield` \(\^4\.0\) — First-party plugin: `php artisan alfakit:add shield`/')
        ->toMatch('/`composer\.json:\d+` — `filament\/spatie-laravel-media-library-plugin`.*FileUpload/')
        ->toMatch('/`composer\.json:\d+` — `pxlrbt\/filament-excel`.*ExportAction/')
        // Themes, config, tests, icons.
        ->toContain('`resources/css/filament/admin/theme.css` — Filament theme file — Run `php artisan make:alfakit-theme admin`')
        ->toMatch('/`config\/filament\.php:\d+` — `broadcasting`/')
        ->toMatch('/`config\/filament\.php:\d+` — `default_filesystem_disk` — AlfaKit: alfakit\.default_filesystem_disk/')
        ->toMatch('/`tests\/Feature\/PostResourceFeature\.php:\d+` — Livewire test helpers.*alfakit\(\)->testPage/')
        ->toMatch('/`app\/Alfakit\/Widgets\/StatsOverview\.php:\d+` — Icon `heroicon-\?-arrow-trending-up` became `arrow-trending-up`/');

    // Nothing was silently dropped: every class without an equivalent is listed with a line.
    expect($report)->toMatch('/`app\/Livewire\/ContactForm\.php:\d+` — `Filament\\\\Forms\\\\Concerns\\\\InteractsWithForms`/');
});

it('does not touch files in a dry run but writes the report', function (): void {
    $before = snapshotFiles($this->sandbox);

    runMigration($this, ['--dry-run' => true, '--keep-filament' => false])
        ->expectsOutputToContain('[dry run]')
        ->assertSuccessful();

    expect(snapshotFiles($this->sandbox))->toBe($before)
        ->and($this->files->isDirectory($this->sandbox.'/app/Filament'))->toBeTrue()
        ->and($this->files->exists($this->sandbox.'/resources/js/alfakit/app.tsx'))->toBeFalse()
        ->and(array_filter($this->files->directories($this->sandbox.'/storage'), static fn (string $dir): bool => str_contains($dir, 'alfakit-migration-backup-')))->toBe([]);

    $report = $this->files->get($this->sandbox.'/alfakit-migration-report.md');

    expect($report)
        ->toContain('dry run: nothing was changed')
        ->toContain('## What would be done')
        ->toContain('Moved `app/Filament` to `app/Alfakit`')
        ->toMatch('/`app\/Alfakit\/Pages\/Settings\.php:\d+` — Custom Blade view/')
        ->toContain('`composer remove filament/filament filament/spatie-laravel-media-library-plugin bezhansalleh/filament-shield --no-interaction --no-scripts`');
});

it('is idempotent: a second run finds nothing to migrate', function (): void {
    runMigration($this)->assertSuccessful();
    $after = snapshotFiles($this->sandbox);

    runMigration($this)
        ->expectsOutputToContain('Nothing to migrate')
        ->assertSuccessful();

    expect(snapshotFiles($this->sandbox))->toBe($after);
});

it('refuses a dirty git working tree unless forced', function (): void {
    $git = new Process(['git', 'init', '-q'], $this->sandbox);
    $git->run();

    if (! $git->isSuccessful()) {
        $this->markTestSkipped('git is not available');
    }

    runMigration($this)
        ->expectsOutputToContain('uncommitted changes')
        ->assertFailed();

    expect($this->files->isDirectory($this->sandbox.'/app/Filament'))->toBeTrue();

    runMigration($this, ['--force' => true])->assertSuccessful();

    expect($this->files->isDirectory($this->sandbox.'/app/Alfakit'))->toBeTrue()
        ->and(array_filter($this->files->directories($this->sandbox.'/storage'), static fn (string $dir): bool => str_contains($dir, 'alfakit-migration-backup-')))->toBe([]);
});

it('stops before installing the frontend when there is no Inertia app', function (): void {
    $this->files->delete($this->sandbox.'/package.json');

    runMigration($this)
        ->expectsOutputToContain('No Inertia app detected')
        ->assertSuccessful();

    expect($this->files->exists($this->sandbox.'/app/Alfakit/Resources/PostResource.php'))->toBeTrue()
        ->and($this->files->exists($this->sandbox.'/resources/js/alfakit/app.tsx'))->toBeFalse()
        ->and($this->files->get($this->sandbox.'/alfakit-migration-report.md'))->toContain('Install an Inertia starter kit');
});

it('boots the rewritten resource in a panel', function (): void {
    runMigration($this)->assertSuccessful();

    foreach ([
        'app/Models/Post.php', 'app/Models/User.php', 'app/Policies/PostPolicy.php',
        'app/Alfakit/Resources/PostResource.php',
        'app/Alfakit/Resources/PostResource/Pages/ListPosts.php',
        'app/Alfakit/Resources/PostResource/Pages/CreatePost.php',
        'app/Alfakit/Resources/PostResource/Pages/EditPost.php',
        'app/Alfakit/Resources/PostResource/Pages/ViewPost.php',
        'app/Alfakit/Resources/PostResource/RelationManagers/CommentsRelationManager.php',
    ] as $file) {
        $class = 'App\\'.str_replace('/', '\\', substr($file, 4, -4));

        if (! class_exists($class, false)) {
            require $this->sandbox.'/'.$file;
        }
    }

    Gate::policy(App\Models\Post::class, App\Policies\PostPolicy::class);

    $manager = alfakit();

    if (! $manager->hasPanel('migrated')) {
        $panel = Panel::make()->id('migrated')->path('migrated')->login()->middleware(['web'])->resources([App\Alfakit\Resources\PostResource::class]);
        $manager->registerPanel($panel);
        $router = app(Router::class);
        $panel->registerRoutes($router);
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    $user = App\Models\User::create(['name' => 'Admin', 'email' => 'admin@acme.test', 'password' => 'secret']);
    App\Models\Post::create(['author_id' => $user->id, 'title' => 'Hello', 'slug' => 'hello', 'is_published' => true]);

    expect(App\Alfakit\Resources\PostResource::getNavigationIcon())->toBe('file-text');

    $props = inertiaProps(inertiaGet($this->actingAs($user), '/migrated/posts'));

    expect(json_encode($props))->toContain('Hello')->toContain('bulkActions')->toContain('Published');

    inertiaGet($this->actingAs($user), '/migrated/posts/create')->assertOk();
});

it('migrates with the Rector set when a Rector binary is available', function (): void {
    $rector = dirname(__DIR__, 4).'/vendor/bin/rector';

    if (! is_file($rector)) {
        $this->markTestSkipped('Rector is not installed');
    }

    runMigration($this, ['--rector' => $rector])->assertSuccessful();

    assertMigratedFixture($this, $this->sandbox);

    expect($this->files->get($this->sandbox.'/alfakit-migration-report.md'))
        ->toContain('Rector (')
        ->toMatch('/`app\/Alfakit\/Pages\/Settings\.php:\d+` — Custom Blade view/')
        ->toMatch('/`app\/Providers\/Alfakit\/AdminPanelProvider\.php:\d+` — Plugin `BezhanSalleh\\\\FilamentShield\\\\FilamentShieldPlugin` removed/');
})->group('rector');
