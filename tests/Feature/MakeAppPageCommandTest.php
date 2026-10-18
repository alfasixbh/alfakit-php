<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

/*
 * `make:alfakit-page --component`: an app page (PHP class with an #[Expose] method and
 * getViewData()) plus its frontend component, registered under `pages` in the app and SSR
 * entries. Each test works in its own temporary application directory.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/alfakit-app-pages-'.uniqid();
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->sandbox.'/resources/js/alfakit');
    $files->ensureDirectoryExists($this->sandbox.'/public');

    $this->originalBasePath = $this->app->basePath();
    $this->app->setBasePath($this->sandbox);
    config(['alfakit.file_generation.path' => $this->sandbox.'/app/Alfakit', 'alfakit.file_generation.namespace' => 'App\\Alfakit']);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    (new Filesystem)->deleteDirectory($this->sandbox);
});

/** Generated pages live under the default panel's folder when there are several panels. */
function appPagePath(string $path): string
{
    $panel = count(alfakit()->getPanels()) > 1 ? ucfirst((string) alfakit()->getDefaultPanel()?->getId()).'/' : '';

    return app_path("Alfakit/{$panel}{$path}");
}

function appPageEntry(string $adapter, string $kind): string
{
    $stub = dirname(__DIR__, 2)."/stubs/frontend/{$adapter}/{$kind}.stub";

    return (string) file_get_contents($stub);
}

/** The installed entries; `$discover = false` writes ones without auto-registration. */
function writeAppPageEntries(string $adapter, string $extension, bool $discover = true): void
{
    foreach (['app', 'ssr'] as $kind) {
        $entry = appPageEntry($adapter, $kind);

        if (! $discover) {
            $entry = (string) preg_replace('/^\s*discover: .*\n/m', '', $entry);
        }

        file_put_contents(resource_path("js/alfakit/{$kind}.{$extension}"), $entry);
    }
}

it('generates an app page with an auto-registered React component', function (): void {
    writeAppPageEntries('react', 'tsx');

    $this->artisan('make:alfakit-page', ['name' => 'Pos', '--component' => true, '--adapter' => 'react', '--fullscreen' => true])->assertSuccessful();

    $class = file_get_contents(appPagePath('Pages/Pos.php'));
    $component = file_get_contents(resource_path('js/alfakit/pages/Pos.tsx'));
    $app = file_get_contents(resource_path('js/alfakit/app.tsx'));
    $ssr = file_get_contents(resource_path('js/alfakit/ssr.tsx'));

    expect($class)
        ->toContain('\\Pages;')
        ->toContain('class Pos extends Page')
        ->toContain("protected static ?string \$component = 'Pages/Pos';")
        ->toContain("protected static string \$layout = 'fullscreen';")
        ->toContain('#[Expose(')
        ->toContain('protected function getViewData(): array')
        ->and($component)
        ->toContain("from '@alfakit/react'")
        ->toContain('export default function Pos(')
        ->toContain("usePageMethod<{ message: string }, { name: string }>('greet')")
        ->toContain('<AppPage>')
        // `discover` picks pages/Pos.tsx up as `Pages/Pos`: the entries stay as installed.
        ->and($app)->toBe(appPageEntry('react', 'app'))
        ->and($ssr)->toBe(appPageEntry('react', 'ssr'));

    // The generated PHP compiles and the page is a valid app page.
    expect(shell_exec('php -l '.escapeshellarg(appPagePath('Pages/Pos.php')).' 2>&1'))->toContain('No syntax errors');

    // Running it again changes nothing.
    $this->artisan('make:alfakit-page', ['name' => 'Pos', '--component' => true, '--adapter' => 'react'])->assertSuccessful();

    expect(file_get_contents(resource_path('js/alfakit/app.tsx')))->toBe($app)
        ->and(file_get_contents(resource_path('js/alfakit/ssr.tsx')))->toBe($ssr);
});

it('registers Vue and Svelte app page components in entries without auto-registration', function (string $adapter, string $extension, string $entryExtension, string $needle): void {
    writeAppPageEntries($adapter, $entryExtension, discover: false);

    $this->artisan('make:alfakit-page', ['name' => 'FulfilmentBoard', '--component' => true, '--adapter' => $adapter])->assertSuccessful();

    $component = file_get_contents(resource_path("js/alfakit/pages/FulfilmentBoard.{$extension}"));
    $app = file_get_contents(resource_path("js/alfakit/app.{$entryExtension}"));

    expect($component)->toContain("from '@alfakit/{$adapter}'")->toContain($needle)->toContain("usePageMethod<{ message: string }, { name: string }>('greet')")
        ->and($app)->toContain("import FulfilmentBoardPage from './pages/FulfilmentBoard.{$extension}'")
        ->toContain("'Pages/FulfilmentBoard': FulfilmentBoardPage,")
        ->and(file_get_contents(appPagePath('Pages/FulfilmentBoard.php')))->not->toContain("'fullscreen'");
})->with([
    'vue' => ['vue', 'vue', 'ts', '<AppPage>'],
    'svelte' => ['svelte', 'svelte', 'ts', '<AppPage>'],
]);

it('generates a resource app page', function (): void {
    writeAppPageEntries('react', 'tsx');

    $this->artisan('make:alfakit-page', ['name' => 'Board', '--component' => true, '--adapter' => 'react', '--resource' => 'Order'])->assertSuccessful();

    expect(file_get_contents(appPagePath('Resources/OrderResource/Pages/Board.php')))
        ->toContain('use AlfaSix\\Alfakit\\Resources\\Pages\\Page;')
        ->toContain('protected static string $resource = OrderResource::class;')
        ->toContain("protected static ?string \$component = 'Pages/Board';")
        ->and(file_exists(resource_path('js/alfakit/pages/Board.tsx')))->toBeTrue();
});

it('keeps generating schema pages without --component', function (): void {
    $this->artisan('make:alfakit-page', ['name' => 'Settings'])->assertSuccessful();

    expect(file_get_contents(appPagePath('Pages/Settings.php')))->toContain('public function content(Schema $schema): Schema')
        ->and(is_dir(resource_path('js/alfakit/pages')))->toBeFalse();
});
