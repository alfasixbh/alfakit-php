<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Facades\Alfakit;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\ContactForm;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\PostsTable;
use AlfaSix\Alfakit\Tests\Fixtures\Standalone\PostStats;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;

/*
 * Outside panels: `Alfakit::render()`, `make:alfakit-page --standalone` and `alfakit:auth`.
 */

// ---------------------------------------------------------------- Alfakit::render()

it('renders an app page with components built from classes, [class, parameters] pairs and plain props', function (): void {
    $author = User::query()->create(['name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'password']);

    Route::middleware('web')->get('/overview', fn () => Alfakit::render('overview', [
        'contact' => ContactForm::class,
        'posts' => [PostsTable::class, ['author' => $author]],
        'stats' => PostStats::make(),
        'title' => 'Overview',
        'list' => ['a', 'b'],
    ]));

    $response = inertiaGet($this, '/overview');
    $props = inertiaProps($response);

    expect($response->json('component'))->toBe('overview')
        ->and($props['contact']['class'])->toBe(ContactForm::class)
        ->and($props['posts']['class'])->toBe(PostsTable::class)
        ->and($props['posts']['content'][0]['type'])->toBe('Table')
        ->and($props['stats']['alfakit'])->toBe('standalone')
        ->and($props['title'])->toBe('Overview')
        ->and($props['list'])->toBe(['a', 'b']);
});

// ---------------------------------------------------------------- Generators

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-outside-'.uniqid();
    $files = new Filesystem;

    foreach (['app/Alfakit', 'app/Providers', 'bootstrap', 'routes', 'config', 'database/migrations', 'resources/js/pages', 'resources/js/layouts'] as $directory) {
        $files->ensureDirectoryExists($this->sandbox.'/'.$directory);
    }

    file_put_contents($this->sandbox.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
    file_put_contents($this->sandbox.'/routes/web.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::inertia('/', 'welcome');\n");

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('generates a standalone page: the component and the Inertia page for each adapter', function (string $adapter, string $file, string $render): void {
    $this->artisan('make:alfakit-page', ['name' => 'OrdersOverview', '--standalone' => true, '--adapter' => $adapter])
        ->expectsOutputToContain("Alfakit::render('".($adapter === 'react' ? 'orders-overview' : 'OrdersOverview')."'")
        ->assertSuccessful();

    $php = (string) file_get_contents($this->sandbox.'/app/Alfakit/Standalone/OrdersOverview.php');
    $page = (string) file_get_contents($this->sandbox.'/resources/js/pages/'.$file);

    expect($php)->toContain('class OrdersOverview extends Component')
        ->and(shell_exec('php -l '.escapeshellarg($this->sandbox.'/app/Alfakit/Standalone/OrdersOverview.php')))->toContain('No syntax errors')
        ->and($page)->toContain("@alfakit/{$adapter}")
        ->toContain($render)
        ->not->toContain('{{ ');
})->with([
    'react' => ['react', 'orders-overview.tsx', '<AlfakitComponent component={ordersOverview} />'],
    'vue' => ['vue', 'OrdersOverview.vue', '<AlfakitComponent :component="ordersOverview" />'],
    'svelte' => ['svelte', 'OrdersOverview.svelte', '<AlfakitComponent component={ordersOverview} />'],
]);

it('sets up the auth kit: provider, routes and the guest and account pages', function (): void {
    $this->artisan('alfakit:auth', ['--adapter' => 'react', '--mfa' => true, '--no-interaction' => true])->assertSuccessful();

    $provider = (string) file_get_contents($this->sandbox.'/app/Providers/AuthKitServiceProvider.php');
    $guest = (string) file_get_contents($this->sandbox.'/resources/js/pages/auth/alfakit.tsx');
    $account = (string) file_get_contents($this->sandbox.'/resources/js/pages/settings/alfakit.tsx');

    expect($provider)->toContain("->inertiaPages(guest: 'auth/alfakit', account: 'settings/alfakit')")
        ->toContain('->multiFactorAuthentication([AppAuthentication::make()->recoverable(), EmailAuthentication::make()])')
        ->not->toContain('->fortify()')
        ->and(shell_exec('php -l '.escapeshellarg($this->sandbox.'/app/Providers/AuthKitServiceProvider.php')))->toContain('No syntax errors')
        ->and((string) file_get_contents($this->sandbox.'/bootstrap/providers.php'))->toContain('App\\Providers\\AuthKitServiceProvider::class')
        ->and((string) file_get_contents($this->sandbox.'/routes/web.php'))->toContain('\\AlfaSix\\Alfakit\\AuthKit\\AuthKit::routes();')
        // No starter kit layout: the pages bring their own centred layout.
        ->and($guest)->toContain('<AlfakitComponent component={component} />')->toContain('min-h-svh')
        ->and($account)->toContain('Object.entries(sections)')->toContain('{authKit.title}')
        ->and(glob($this->sandbox.'/database/migrations/*_add_alfakit_multi_factor_columns_to_users_table.php'))->toHaveCount(1);

    // A second run changes nothing.
    $this->artisan('alfakit:auth', ['--adapter' => 'react', '--no-interaction' => true])->assertSuccessful();

    expect(substr_count((string) file_get_contents($this->sandbox.'/routes/web.php'), 'AuthKit::routes()'))->toBe(1);
});

it('uses the starter kit layouts and Fortify\'s backend when asked', function (string $adapter, string $guestFile, string $accountFile): void {
    file_put_contents($this->sandbox.'/resources/js/layouts/'.($adapter === 'react' ? 'app-layout.tsx' : "AppLayout.{$adapter}"), '');
    file_put_contents($this->sandbox.'/config/fortify.php', "<?php\n\nreturn [\n    'guard' => 'web',\n    'views' => true,\n];\n");

    $this->artisan('alfakit:auth', ['--adapter' => $adapter, '--fortify' => true, '--no-interaction' => true])->assertSuccessful();

    $guest = (string) file_get_contents($this->sandbox.'/resources/js/pages/'.$guestFile);
    $account = (string) file_get_contents($this->sandbox.'/resources/js/pages/'.$accountFile);

    expect((string) file_get_contents($this->sandbox.'/app/Providers/AuthKitServiceProvider.php'))->toContain('->fortify()')
        ->and((string) file_get_contents($this->sandbox.'/config/fortify.php'))->toContain("'views' => false")
        ->and($guest)->not->toContain('min-h-svh')->toContain("@alfakit/{$adapter}")
        ->and($account)->toContain('section')->not->toContain('{{ ');
})->with([
    'react' => ['react', 'auth/alfakit.tsx', 'settings/alfakit.tsx'],
    'vue' => ['vue', 'auth/Alfakit.vue', 'settings/Alfakit.vue'],
    'svelte' => ['svelte', 'auth/Alfakit.svelte', 'settings/Alfakit.svelte'],
]);
