<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\StarterKit\StarterKitInstaller;
use Illuminate\Filesystem\Filesystem;

/*
 * `alfakit:install --starter-kit` against copies of the files of Laravel's React, Vue and
 * Svelte starter kits (tests/Fixtures/StarterKits/<kit>).
 */

function starterKitApp(string $kit): string
{
    $path = sys_get_temp_dir().'/alfakit-starter-kit-'.$kit.'-'.uniqid();

    (new Filesystem)->copyDirectory(dirname(__DIR__, 2).'/Fixtures/StarterKits/'.$kit, $path);

    return $path;
}

dataset('starter kits', [
    'react' => ['react', 'resources/js/components/app-sidebar.tsx', 'resources/js/pages/Alfakit/Page.tsx', 'resources/js/components/alfakit-nav.tsx', "import { AlfakitNav } from '@/components/alfakit-nav';"],
    'vue' => ['vue', 'resources/js/components/AppSidebar.vue', 'resources/js/pages/Alfakit/Page.vue', 'resources/js/components/AlfakitNav.vue', "import AlfakitNav from '@/components/AlfakitNav.vue';"],
    'svelte' => ['svelte', 'resources/js/components/AppSidebar.svelte', 'resources/js/pages/Alfakit/Page.svelte', 'resources/js/components/AlfakitNav.svelte', "    import AlfakitNav from '@/components/AlfakitNav.svelte';"],
]);

it('detects the starter kit', function (string $kit): void {
    expect(StarterKitInstaller::detect(starterKitApp($kit)))->toBe($kit)
        ->and(StarterKitInstaller::detect(sys_get_temp_dir()))->toBeNull();
})->with(['react', 'vue', 'svelte']);

it('wires Alfakit into the starter kit, idempotently', function (string $kit, string $sidebar, string $page, string $nav, string $import): void {
    $app = starterKitApp($kit);
    $entry = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)['entry']);

    $changes = (new StarterKitInstaller($app, $kit))->install('admin', 'admin');
    $statuses = collect($changes)->mapWithKeys(fn (array $change): array => [$change['path'] => $change['status']]);

    expect($statuses->all())->toBe([
        $page => 'created',
        $nav => 'created',
        $sidebar => 'updated',
        StarterKitInstaller::files($kit)['sidebarHeader'] => 'updated',
        StarterKitInstaller::files($kit)['header'] => 'updated',
        'resources/css/app.css' => 'updated',
        StarterKitInstaller::files($kit)['entry'] => 'unchanged',
        'app/Providers/Alfakit/AdminPanelProvider.php' => 'created',
        'bootstrap/providers.php' => 'updated',
    ]);

    // The page renders in the host layout through the renderer's starter kit helper.
    expect(file_get_contents($app.'/'.$page))
        ->toContain($kit === 'svelte' ? 'createStarterKitLayout({ layout: AppLayout })' : 'createStarterKitPage({ layout: AppLayout })')
        ->toContain("@alfakit/{$kit}");

    // The navigation component uses the host's own sidebar primitives.
    expect(file_get_contents($app.'/'.$nav))
        ->toContain("from '@/components/ui/sidebar'")
        ->toContain('useAlfakitNavigation()');

    // The sidebar gains the import and a marked <AlfakitNav /> block after <NavMain />.
    $sidebarContents = (string) file_get_contents($app.'/'.$sidebar);

    expect($sidebarContents)->toContain($import."\n")
        ->toContain(StarterKitInstaller::MARKER)
        ->and(substr_count($sidebarContents, '<AlfakitNav />'))->toBe(1)
        ->and(strpos($sidebarContents, '<AlfakitNav />'))->toBeGreaterThan(strpos($sidebarContents, '<NavMain'));

    // Both app layouts' headers gain the search field and the bell in a marked block, the
    // sidebar layout's at the end of the row, the header layout's before the user menu.
    foreach (['sidebarHeader', 'header'] as $file) {
        $header = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)[$file]);

        expect($header)->toContain(StarterKitInstaller::HEADER_MARKER)
            ->toContain("import { AlfakitNotifications, AlfakitSearch } from '@alfakit/{$kit}';")
            ->and(substr_count($header, '<AlfakitSearch />'))->toBe(1)
            ->and(substr_count($header, '<AlfakitNotifications />'))->toBe(1);
    }

    $header = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)['header']);

    expect(strpos($header, '<AlfakitNotifications />'))->toBeLessThan(strpos($header, '<DropdownMenu>'));

    // The starter kit stylesheet (no palette, so the host's tokens stay) after Tailwind.
    $css = (string) file_get_contents($app.'/resources/css/app.css');

    expect($css)->toStartWith("@import 'tailwindcss';\n@import '@alfakit/{$kit}/standalone.css';\n@source '../../node_modules/@alfakit/{$kit}/dist';\n")
        ->and(file_get_contents($app.'/'.StarterKitInstaller::files($kit)['entry']))->toBe($entry);

    $provider = (string) file_get_contents($app.'/app/Providers/Alfakit/AdminPanelProvider.php');

    expect($provider)->toContain('->starterKit()')
        ->toContain("->path('admin')")
        ->not->toContain('->login()')
        ->and(file_get_contents($app.'/bootstrap/providers.php'))->toContain('App\\Providers\\Alfakit\\AdminPanelProvider::class');

    // A second run changes nothing.
    $snapshot = collect([$page, $nav, $sidebar, StarterKitInstaller::files($kit)['sidebarHeader'], StarterKitInstaller::files($kit)['header'], 'resources/css/app.css', 'bootstrap/providers.php'])
        ->mapWithKeys(fn (string $file): array => [$file => file_get_contents($app.'/'.$file)]);

    $again = (new StarterKitInstaller($app, $kit))->install('admin', 'admin');

    expect(collect($again)->pluck('status')->unique()->values()->all())->toBe(['unchanged'])
        ->and($snapshot->map(fn (string $contents, string $file): bool => file_get_contents($app.'/'.$file) === $contents)->filter()->count())->toBe(7);

    (new Filesystem)->deleteDirectory($app);
})->with('starter kits');

it('skips a sidebar it does not recognise', function (): void {
    expect(StarterKitInstaller::addNavToSidebar('<template><Sidebar /></template>', 'vue'))->toBeNull();

    $app = starterKitApp('react');
    file_put_contents($app.'/resources/js/components/app-sidebar.tsx', 'export function AppSidebar() { return null }');

    $changes = collect((new StarterKitInstaller($app, 'react'))->install())->keyBy('path');

    expect($changes['resources/js/components/app-sidebar.tsx']['status'])->toBe('skipped');

    (new Filesystem)->deleteDirectory($app);
});

it('leaves the headers alone with --no-header-widgets', function (): void {
    $app = starterKitApp('react');
    $header = (string) file_get_contents($app.'/resources/js/components/app-sidebar-header.tsx');

    $changes = collect((new StarterKitInstaller($app, 'react'))->install('admin', 'admin', headerWidgets: false))->keyBy('path');

    expect($changes)->not->toHaveKey('resources/js/components/app-sidebar-header.tsx')
        ->and(file_get_contents($app.'/resources/js/components/app-sidebar-header.tsx'))->toBe($header)
        ->and(StarterKitInstaller::addWidgetsToHeader('<header></div>', 'react', sidebarLayout: false))->toBeNull();

    (new Filesystem)->deleteDirectory($app);
});

it('runs from alfakit:install --starter-kit', function (): void {
    $app = starterKitApp('vue');
    file_put_contents($app.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    $this->app->setBasePath($app);

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true, '--path' => 'app'])
        ->expectsOutputToContain('resources/js/pages/Alfakit/Page.vue')
        ->assertSuccessful();

    expect(file_get_contents($app.'/app/Providers/Alfakit/AdminPanelProvider.php'))->toContain("->path('app')")
        ->and(file_get_contents($app.'/resources/js/components/AppSidebar.vue'))->toContain('<AlfakitNav />')
        ->and(file_get_contents($app.'/resources/js/components/AppSidebarHeader.vue'))->toContain('<AlfakitSearch />');

    $vue = starterKitApp('vue');
    file_put_contents($vue.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    $this->app->setBasePath($vue);

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true, '--no-header-widgets' => true])->assertSuccessful();

    expect(file_get_contents($vue.'/resources/js/components/AppSidebarHeader.vue'))->not->toContain('AlfakitSearch');

    (new Filesystem)->deleteDirectory($app);
    (new Filesystem)->deleteDirectory($vue);
});
