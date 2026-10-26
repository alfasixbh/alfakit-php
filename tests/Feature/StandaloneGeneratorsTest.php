<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\InstallCommand;
use Illuminate\Filesystem\Filesystem;

/*
 * Generators for components used outside panels (`make:alfakit-form`, `-table`,
 * `-infolist`, `-component`) and the `alfakit:install --standalone` stylesheet wiring.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-standalone-'.uniqid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->sandbox.'/app/Alfakit');
    $files->ensureDirectoryExists($this->sandbox.'/resources/js/pages');
    $files->ensureDirectoryExists($this->sandbox.'/resources/css');

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

it('generates a standalone form, table, infolist and component that compile', function (): void {
    $this->artisan('make:alfakit-form', ['name' => 'ContactForm'])->assertSuccessful();
    $this->artisan('make:alfakit-table', ['name' => 'OrdersTable', '--model' => 'Order'])
        ->expectsOutputToContain('OrdersTable::make()]')
        ->assertSuccessful();
    $this->artisan('make:alfakit-infolist', ['name' => 'OrderDetails', '--model' => 'App\\Models\\Order'])->assertSuccessful();
    $this->artisan('make:alfakit-component', ['name' => 'AccountOverview'])->assertSuccessful();

    $form = file_get_contents($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php');
    $table = file_get_contents($this->sandbox.'/app/Alfakit/Standalone/OrdersTable.php');
    $infolist = file_get_contents($this->sandbox.'/app/Alfakit/Standalone/OrderDetails.php');
    $component = file_get_contents($this->sandbox.'/app/Alfakit/Standalone/AccountOverview.php');

    expect($form)->toContain('namespace App\\Alfakit\\Standalone;')
        ->toContain('class ContactForm extends Form')
        ->toContain('public function submit(array $data): mixed')
        ->and($table)->toContain('use App\\Models\\Order;')
        // A table lists every order: no `public Order $order;` and no `make(['order' => …])`.
        ->not->toContain('public Order $order;')
        ->toContain('->query(Order::query())')
        ->and($infolist)->toContain('use App\\Models\\Order;')
        ->toContain('class OrderDetails extends Infolist')
        ->and($component)->toContain('class AccountOverview extends Component')
        ->toContain('public function actions(): array');

    foreach (['ContactForm', 'OrdersTable', 'OrderDetails', 'AccountOverview'] as $class) {
        $lint = shell_exec('php -l '.escapeshellarg($this->sandbox."/app/Alfakit/Standalone/{$class}.php"));

        expect($lint)->toContain('No syntax errors');
    }
});

it('does not overwrite an existing class without --force', function (): void {
    $this->artisan('make:alfakit-form', ['name' => 'ContactForm'])->assertSuccessful();
    file_put_contents($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php', '<?php // mine');

    $this->artisan('make:alfakit-form', ['name' => 'ContactForm'])->assertSuccessful();
    expect(file_get_contents($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php'))->toBe('<?php // mine');

    $this->artisan('make:alfakit-form', ['name' => 'ContactForm', '--force' => true])->assertSuccessful();
    expect(file_get_contents($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php'))->toContain('extends Form');
});

it('adds the standalone stylesheet after the Tailwind import', function (): void {
    $css = "@import 'tailwindcss';\n\n@import 'tw-animate-css';\n\n:root {\n  --radius: 0.5rem;\n}\n";

    expect(InstallCommand::addStandaloneStyles($css, 'react'))->toBe(
        "@import 'tailwindcss';\n@import '@alfakit/react/standalone.css';\n@source '../../node_modules/@alfakit/react/dist';\n\n@import 'tw-animate-css';\n\n:root {\n  --radius: 0.5rem;\n}\n",
    );

    // Without a Tailwind import the lines go first.
    expect(InstallCommand::addStandaloneStyles("body { margin: 0 }\n", 'vue'))->toStartWith("@import '@alfakit/vue/standalone.css';\n");
});

it('installs for use outside panels: styles, an example form and page, no panel', function (): void {
    file_put_contents($this->sandbox.'/resources/css/app.css', "@import 'tailwindcss';\n");
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/resources/views');
    file_put_contents($this->sandbox.'/resources/views/app.blade.php', '@inertia');

    $this->artisan('alfakit:install', ['--standalone' => true, '--no-npm' => true])
        // A closure route: Route::inertia() with a component in its props can't be cached (php artisan optimize).
        ->expectsOutputToContain("Route::get('/contact', fn () => Inertia::render('Contact'")
        ->doesntExpectOutputToContain('Route::inertia(')
        ->assertSuccessful();

    expect(file_get_contents($this->sandbox.'/resources/css/app.css'))->toContain("@import '@alfakit/vue/standalone.css';")
        ->and(file_exists($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php'))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/resources/js/pages/Contact.vue'))->toContain('<AlfakitComponent :component="form" />')
        ->and(file_exists($this->sandbox.'/app/Providers/Alfakit/AdminPanelProvider.php'))->toBeFalse();
});

it('refuses a standalone install in an app without an Inertia frontend, and writes nothing', function (): void {
    file_put_contents($this->sandbox.'/resources/css/app.css', "@import 'tailwindcss';\n");
    file_put_contents($this->sandbox.'/package.json', json_encode(['devDependencies' => ['vite' => '^7.0', 'tailwindcss' => '^4.0']]));

    $this->artisan('alfakit:install', ['--standalone' => true, '--adapter' => 'react', '--no-npm' => true])
        ->expectsOutputToContain('no Inertia frontend')
        ->expectsOutputToContain('laravel new --react')
        ->assertFailed();

    expect(file_get_contents($this->sandbox.'/resources/css/app.css'))->toBe("@import 'tailwindcss';\n")
        ->and(file_exists($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php'))->toBeFalse()
        ->and(file_exists($this->sandbox.'/resources/js/pages/contact.tsx'))->toBeFalse();
});

it('warns when the stylesheet has no shadcn tokens for standalone components', function (): void {
    file_put_contents($this->sandbox.'/resources/css/app.css', "@import 'tailwindcss';\n");
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/resources/views');
    file_put_contents($this->sandbox.'/resources/views/app.blade.php', '@inertia');

    $this->artisan('alfakit:install', ['--standalone' => true, '--no-npm' => true])
        ->expectsOutputToContain('defines no shadcn tokens')
        ->assertSuccessful();
});

it('does not publish the import/export migrations a second time when the app already has them', function (): void {
    file_put_contents($this->sandbox.'/resources/css/app.css', "@import 'tailwindcss';\n");
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/resources/views');
    file_put_contents($this->sandbox.'/resources/views/app.blade.php', '@inertia');
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/database/migrations');
    // Published by an earlier `alfakit:install --import-export` (under its own date).
    file_put_contents($this->sandbox.'/database/migrations/2026_01_01_000000_create_alfakit_import_export_tables.php', '<?php');

    // The publish destination was fixed when the provider booted (the Testbench app's migrations).
    $target = dirname((string) array_values(Illuminate\Support\ServiceProvider::pathsToPublish(null, 'alfakit-import-export-migrations'))[0]);
    $before = glob($target.'/*_create_alfakit_import_export_tables.php') ?: [];

    try {
        $this->artisan('alfakit:install', ['--standalone' => true, '--import-export' => true, '--no-npm' => true])
            ->expectsOutputToContain('already published')
            ->assertSuccessful();

        expect(glob($target.'/*_create_alfakit_import_export_tables.php') ?: [])->toBe($before);
    } finally {
        array_map('unlink', array_diff(glob($target.'/*_create_alfakit_import_export_tables.php') ?: [], $before));
    }
});

it('only publishes the import/export migrations when AlfaKit is already installed (a starter kit app)', function (): void {
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/react' => '^3.0']]));
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/app/Providers/Alfakit');
    file_put_contents($this->sandbox.'/app/Providers/Alfakit/AdminPanelProvider.php', "<?php\n// ->starterKit()\n");
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/database/migrations');
    file_put_contents($this->sandbox.'/database/migrations/2026_01_01_000000_create_alfakit_import_export_tables.php', '<?php');

    $this->artisan('alfakit:install', ['--import-export' => true, '--no-npm' => true])->assertSuccessful();

    // No admin panel entry next to the starter kit.
    expect(file_exists($this->sandbox.'/resources/js/alfakit/app.tsx'))->toBeFalse()
        ->and(file_exists($this->sandbox.'/resources/css/alfakit.css'))->toBeFalse()
        ->and((string) file_get_contents($this->sandbox.'/app/Providers/Alfakit/AdminPanelProvider.php'))->toBe("<?php\n// ->starterKit()\n");
});

it('does not point at a panel it did not create with --no-panel', function (): void {
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/react' => '^3.0']]));

    $this->artisan('alfakit:install', ['--adapter' => 'react', '--panel' => 'other', '--no-panel' => true, '--no-npm' => true])
        ->doesntExpectOutputToContain('OtherPanelProvider')
        ->doesntExpectOutputToContain('visit /other')
        ->assertSuccessful();

    expect(file_exists($this->sandbox.'/app/Providers/Alfakit/OtherPanelProvider.php'))->toBeFalse();
});

it('shows the component tag in the app renderer\'s syntax', function (): void {
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));

    $this->artisan('make:alfakit-table', ['name' => 'PostsTable'])
        ->expectsOutputToContain('<AlfakitComponent :component="postsTable" />')
        ->assertSuccessful();
});

it('writes the component tag of the app renderer into the generated class docblocks (W-V3-05)', function (string $package, string $form, string $overview): void {
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => [$package => '^3.0']]));

    $this->artisan('make:alfakit-form', ['name' => 'ContactForm'])->assertSuccessful();
    $this->artisan('make:alfakit-component', ['name' => 'AccountOverview'])->assertSuccessful();

    expect(file_get_contents($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php'))->toContain("`{$form}` in the page.")->not->toContain('in Vue')
        ->and(file_get_contents($this->sandbox.'/app/Alfakit/Standalone/AccountOverview.php'))->toContain("then `{$overview}`.");
})->with([
    'vue' => ['@inertiajs/vue3', '<AlfakitComponent :component="form" />', '<AlfakitComponent :component="overview" />'],
    'svelte' => ['@inertiajs/svelte', '<AlfakitComponent component={form} />', '<AlfakitComponent component={overview} />'],
    'react' => ['@inertiajs/react', '<AlfakitComponent component={form} />', '<AlfakitComponent component={overview} />'],
]);

it('says that --ssr changes nothing in a standalone install instead of ignoring it silently (W-G-17)', function (): void {
    file_put_contents($this->sandbox.'/resources/css/app.css', "@import 'tailwindcss';\n");
    file_put_contents($this->sandbox.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    (new Filesystem)->ensureDirectoryExists($this->sandbox.'/resources/views');
    file_put_contents($this->sandbox.'/resources/views/app.blade.php', '@inertia');

    $this->artisan('alfakit:install', ['--standalone' => true, '--ssr' => true, '--no-npm' => true])
        ->expectsOutputToContain('--ssr only sets up the SSR entry of a panel install')
        ->assertSuccessful();

    expect(file_exists($this->sandbox.'/resources/js/alfakit/ssr.ts'))->toBeFalse();
});
