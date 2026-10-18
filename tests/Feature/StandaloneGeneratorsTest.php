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
    $this->artisan('make:alfakit-table', ['name' => 'OrdersTable', '--model' => 'Order'])->assertSuccessful();
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
        ->toContain('public Order $order;')
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

    $this->artisan('alfakit:install', ['--standalone' => true, '--no-npm' => true])->assertSuccessful();

    expect(file_get_contents($this->sandbox.'/resources/css/app.css'))->toContain("@import '@alfakit/vue/standalone.css';")
        ->and(file_exists($this->sandbox.'/app/Alfakit/Standalone/ContactForm.php'))->toBeTrue()
        ->and(file_get_contents($this->sandbox.'/resources/js/pages/Contact.vue'))->toContain('<AlfakitComponent :component="form" />')
        ->and(file_exists($this->sandbox.'/app/Providers/Alfakit/AdminPanelProvider.php'))->toBeFalse();
});
