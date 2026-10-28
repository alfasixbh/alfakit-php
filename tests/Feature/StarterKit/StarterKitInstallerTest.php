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
        StarterKitInstaller::files($kit)['userMenuItems'] => 'created',
        StarterKitInstaller::files($kit)['userMenu'] => 'updated',
        StarterKitInstaller::files($kit)['sidebarHeader'] => 'updated',
        StarterKitInstaller::files($kit)['header'] => 'updated',
        StarterKitInstaller::files($kit)['dashboard'] => 'updated',
        'resources/css/app.css' => 'updated',
        'resources/views/app.blade.php' => 'updated',
        ...collect(StarterKitInstaller::logicalClassFiles($kit))->mapWithKeys(fn (string $file): array => [$file => 'updated'])->all(),
        StarterKitInstaller::files($kit)['entry'] => 'unchanged',
        'app/Providers/Alfakit/AdminPanelProvider.php' => 'created',
        'bootstrap/providers.php' => 'updated',
    ]);

    // The page renders in the host layout through the renderer's starter kit helper.
    expect(file_get_contents($app.'/'.$page))
        ->toContain($kit === 'svelte' ? 'createStarterKitLayout({' : 'createStarterKitPage({')
        ->toContain('layout: AppLayout,')
        // Custom components in resources/js/alfakit register themselves, as with createAlfakit({ discover }).
        ->toContain("'../../alfakit/{fields,columns,entries,widgets,pages,components,blocks}/**/*.".['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'][$kit]."'")
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

    // The sidebar docks on the end of the line: `side` follows the panel language's direction.
    $side = [
        'react' => "side={direction === 'rtl' ? 'right' : 'left'}",
        'vue' => ":side=\"direction === 'rtl' ? 'right' : 'left'\"",
        'svelte' => "side={direction.current === 'rtl' ? 'right' : 'left'}",
    ][$kit];

    expect($sidebarContents)->toContain(StarterKitInstaller::DIRECTION_MARKER)
        ->toContain('<Sidebar collapsible="icon" variant="inset" '.$side.'>')
        ->toContain(($kit === 'svelte' ? '    ' : '')."import { useAlfakitDirection } from '@alfakit/{$kit}';\n")
        ->and(substr_count($sidebarContents, 'const direction = useAlfakitDirection();'))->toBe(1)
        ->and(strpos($sidebarContents, 'const direction = useAlfakitDirection();'))->toBeLessThan(strpos($sidebarContents, '<Sidebar '));

    if ($kit === 'react') {
        expect(strpos($sidebarContents, 'const direction'))->toBeGreaterThan(strpos($sidebarContents, 'export function AppSidebar() {'));
    } else {
        expect(strpos($sidebarContents, 'const direction'))->toBeLessThan(strpos($sidebarContents, '</script>'));
    }

    // The root view's <html> gets the direction from the first paint (and in SSR).
    expect(file_get_contents($app.'/resources/views/app.blade.php'))
        ->toContain(StarterKitInstaller::DIRECTION_MARKER)
        ->toContain('<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}" dir="{{ '.StarterKitInstaller::DIRECTION_CALL.' }}"');

    // Logical margins and alignment in the kit's logo, user info and menus.
    foreach ([...StarterKitInstaller::logicalClassFiles($kit), StarterKitInstaller::files($kit)['sidebarHeader'], StarterKitInstaller::files($kit)['userMenu']] as $file) {
        expect((string) file_get_contents($app.'/'.$file))
            ->not->toContain('text-left text-sm')
            ->not->toContain('"-ml-1"')
            ->not->toMatch('/(ChevronsUpDown|LogOut|Settings) (class|className)="m[lr]-/');
    }

    // The header layout, the password field, the appearance tabs, the two-factor setup, the
    // split auth layout (and React's login) keep none of the kit's physical classes.
    foreach (StarterKitInstaller::logicalClassFiles($kit) as $file) {
        $contents = (string) file_get_contents($app.'/'.$file);

        expect($contents)->toBe(StarterKitInstaller::useLogicalClasses($contents, $kit))
            ->not->toMatch('/["\' ](-?m[lr]-(auto|1|1\.5|2|6|10)|pr-10|right-0|rounded-r-md|text-left|border-l|dark:border-r|bottom-0 left-0)["\' ]/');
    }

    $files = StarterKitInstaller::logicalClassFiles($kit);
    $file = static fn (string $name): string => (string) file_get_contents($app.'/'.collect($files)->first(fn (string $path): bool => str_contains($path, $name)));

    expect($file($kit === 'react' ? 'password-input' : 'PasswordInput'))->toContain("cn('pe-10',")->toContain('inset-y-0 end-0 flex items-center rounded-e-md px-3')
        ->and($file($kit === 'react' ? 'auth-split-layout' : 'AuthSplitLayout'))->toContain('dark:border-e"')->toContain('"me-2 size-8 fill-current text-white"')
        ->and($file($kit === 'react' ? 'two-factor-setup-modal' : 'TwoFactorSetupModal'))->toContain('border-s border-border px-3 hover:bg-muted"')
        ->and($file($kit === 'react' ? 'appearance-tabs' : 'AppearanceTabs'))->toContain('"-ms-1 h-4 w-4"')->toContain('"ms-1.5 text-sm"');

    // The header layout's mobile menu opens from the end of the line, like the sidebar.
    $headerContents = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)['header']);

    expect($headerContents)->not->toContain('side="left"')
        ->toContain($side)
        ->toContain('ms-auto flex items-center space-x-2')
        ->and(substr_count($headerContents, 'const direction = useAlfakitDirection();'))->toBe(1)
        ->and(StarterKitInstaller::addDirectionToHeader('<SheetContent side="right" />', $kit))->toBeNull();

    // The user menu gains the panel's entries (userMenuItems(), Billing…) in a marked block
    // before the separator above Log out, built from the app's own dropdown items.
    expect(file_get_contents($app.'/'.StarterKitInstaller::files($kit)['userMenuItems']))
        ->toContain("from '@/components/ui/dropdown-menu'")
        ->toContain('useAlfakitNavigation()')
        ->toContain('userMenuItems');

    $menu = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)['userMenu']);
    $menuImport = ($kit === 'svelte' ? '    ' : '').StarterKitInstaller::files($kit)['userMenuItemsImport'];

    expect($menu)->toContain(StarterKitInstaller::USER_MENU_MARKER)
        ->toContain($menuImport."\n")
        ->and(substr_count($menu, '<AlfakitUserMenuItems />'))->toBe(1)
        ->and(strpos($menu, $menuImport))->toBeLessThan(strpos($menu, "from '@/components/ui/dropdown-menu'"))
        ->and(strpos($menu, '<AlfakitUserMenuItems />'))->toBeGreaterThan(strpos($menu, 'Settings'))
        ->and(strpos($menu, '<AlfakitUserMenuItems />'))->toBeLessThan(strrpos($menu, '<DropdownMenuSeparator'))
        ->and(StarterKitInstaller::addUserMenuItems($menu, $kit))->toBe($menu)
        ->and(StarterKitInstaller::addUserMenuItems('<template><span /></template>', $kit))->toBeNull();

    // Both app layouts' headers gain the search field, the bell, the AI copilot (nothing
    // without the AI plugin) and the language switcher (nothing with fewer than two
    // languages) in a marked block, the sidebar layout's at the end of the row, the header
    // layout's before the user menu.
    foreach (['sidebarHeader', 'header'] as $file) {
        $header = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)[$file]);
        // The header layout's also has the direction hook of its mobile menu.
        $imports = 'import { AlfakitCopilot, AlfakitLanguageSwitcher, AlfakitNotifications, AlfakitSearch'.($file === 'header' ? ', useAlfakitDirection' : '')." } from '@alfakit/{$kit}';";

        expect($header)->toContain(StarterKitInstaller::HEADER_MARKER)
            ->toContain($imports)
            ->and(substr_count($header, '<AlfakitSearch />'))->toBe(1)
            ->and(substr_count($header, '<AlfakitNotifications />'))->toBe(1)
            ->and(substr_count($header, '<AlfakitCopilot />'))->toBe(1)
            ->and(substr_count($header, '<AlfakitLanguageSwitcher />'))->toBe(1)
            ->and(strpos($header, '<AlfakitCopilot />'))->toBeGreaterThan(strpos($header, '<AlfakitNotifications />'))
            ->and(strpos($header, '<AlfakitLanguageSwitcher />'))->toBeGreaterThan(strpos($header, '<AlfakitCopilot />'));

        // A block from before the copilot existed gains it (`alfakit:add ai`, re-runs).
        $old = (string) preg_replace('/^[ \t]*<AlfakitCopilot \/>\n/m', '', str_replace('AlfakitCopilot, ', '', $header));
        expect($old)->not->toContain('AlfakitCopilot');

        $upgraded = (string) StarterKitInstaller::addCopilotToHeader($old, $kit);

        expect($upgraded)->toContain($imports)
            ->and(substr_count($upgraded, '<AlfakitCopilot />'))->toBe(1)
            ->and(StarterKitInstaller::addWidgetsToHeader($upgraded, $kit, sidebarLayout: $file === 'sidebarHeader'))->toBe($upgraded);

        // A block from before the language switcher existed gains it after the copilot when
        // the installer runs again (`alfakit:install --starter-kit`).
        $old = (string) preg_replace('/^[ \t]*<AlfakitLanguageSwitcher \/>\n/m', '', str_replace('AlfakitLanguageSwitcher, ', '', $header));
        expect($old)->not->toContain('AlfakitLanguageSwitcher');

        $upgraded = (string) StarterKitInstaller::addWidgetsToHeader($old, $kit, sidebarLayout: $file === 'sidebarHeader');

        expect($upgraded)->toBe($header)
            ->and(StarterKitInstaller::addLanguageSwitcherToHeader($upgraded, $kit))->toBe($upgraded);

        // The oldest blocks (search and bell only) gain both, the switcher after the copilot.
        $oldest = (string) preg_replace('/^[ \t]*<Alfakit(Copilot|LanguageSwitcher) \/>\n/m', '', str_replace(['AlfakitCopilot, ', 'AlfakitLanguageSwitcher, '], '', $header));
        $upgraded = (string) StarterKitInstaller::addWidgetsToHeader($oldest, $kit, sidebarLayout: $file === 'sidebarHeader');

        expect($upgraded)->toContain($imports)
            ->and(substr_count($upgraded, '<AlfakitLanguageSwitcher />'))->toBe(1)
            ->and(strpos($upgraded, '<AlfakitLanguageSwitcher />'))->toBeGreaterThan(strpos($upgraded, '<AlfakitCopilot />'));

        // Only the bell to place it by: right after it, with its own import name.
        $bellOnly = (string) preg_replace('/^[ \t]*<Alfakit(Copilot|LanguageSwitcher) \/>\n/m', '', str_replace(['AlfakitCopilot, ', 'AlfakitLanguageSwitcher, '], '', $header));

        expect((string) StarterKitInstaller::addLanguageSwitcherToHeader($bellOnly, $kit))
            ->toContain('import { AlfakitLanguageSwitcher, AlfakitNotifications, AlfakitSearch'.($file === 'header' ? ', useAlfakitDirection' : '')." } from '@alfakit/{$kit}';")
            ->toMatch('/<AlfakitNotifications \/>\n[ \t]*<AlfakitLanguageSwitcher \/>\n/')
            ->and(StarterKitInstaller::addLanguageSwitcherToHeader('<header></header>', $kit))->toBeNull();
    }

    $header = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)['header']);

    expect(strpos($header, '<AlfakitNotifications />'))->toBeLessThan(strpos($header, '<DropdownMenu>'));

    // The dashboard's placeholder row is wrapped in <AlfakitWidgets>, in a marked block: the
    // panel's widgets show there, the boxes while there are none.
    $dashboard = (string) file_get_contents($app.'/'.StarterKitInstaller::files($kit)['dashboard']);
    $class = $kit === 'react' ? 'className' : 'class';

    expect($dashboard)->toContain(StarterKitInstaller::DASHBOARD_MARKER)
        ->toContain("import { AlfakitWidgets } from '@alfakit/{$kit}';\n")
        ->and(substr_count($dashboard, '<AlfakitWidgets>'))->toBe(1)
        ->and(substr_count($dashboard, '</AlfakitWidgets>'))->toBe(1)
        ->and(substr_count($dashboard, 'PlaceholderPattern'))->toBe(substr_count((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/StarterKits/'.$kit.'/'.StarterKitInstaller::files($kit)['dashboard']), 'PlaceholderPattern'))
        ->and(strpos($dashboard, '<AlfakitWidgets>'))->toBeLessThan(strpos($dashboard, '<div '.$class.'="grid auto-rows-min gap-4 md:grid-cols-3">'))
        ->and(strpos($dashboard, '</AlfakitWidgets>'))->toBeLessThan(strpos($dashboard, 'min-h-'));

    if ($kit === 'svelte') {
        // In the instance script, next to the page's own imports.
        expect(strpos($dashboard, 'import { AlfakitWidgets }'))->toBeGreaterThan(strpos($dashboard, '<script lang="ts">'));
    }

    // The starter kit stylesheet (no palette, so the host's tokens stay) after Tailwind.
    $css = (string) file_get_contents($app.'/resources/css/app.css');

    expect($css)->toStartWith("@import 'tailwindcss';\n@import '@alfakit/{$kit}/standalone.css';\n@source '../../node_modules/@alfakit/{$kit}/dist';\n")
        ->and(file_get_contents($app.'/'.StarterKitInstaller::files($kit)['entry']))->toBe($entry);

    $provider = (string) file_get_contents($app.'/app/Providers/Alfakit/AdminPanelProvider.php');

    expect($provider)->toContain('->starterKit()')
        ->toContain("->path('admin')")
        ->not->toContain('->login()')
        // The header's <AlfakitNotifications /> bell needs it (exports, mentions, approvals).
        ->toContain('->databaseNotifications()')
        // The kits import their providers: so does the panel's, for Pint.
        ->and(file_get_contents($app.'/bootstrap/providers.php'))->toContain('use App\\Providers\\Alfakit\\AdminPanelProvider;')->toContain('    AdminPanelProvider::class,');

    // A second run changes nothing.
    $snapshot = collect([$page, $nav, $sidebar, StarterKitInstaller::files($kit)['userMenu'], StarterKitInstaller::files($kit)['userMenuItems'], StarterKitInstaller::files($kit)['sidebarHeader'], StarterKitInstaller::files($kit)['header'], StarterKitInstaller::files($kit)['dashboard'], 'resources/css/app.css', 'bootstrap/providers.php', 'resources/views/app.blade.php'])
        ->mapWithKeys(fn (string $file): array => [$file => file_get_contents($app.'/'.$file)]);

    $again = (new StarterKitInstaller($app, $kit))->install('admin', 'admin');

    expect(collect($again)->pluck('status')->unique()->values()->all())->toBe(['unchanged'])
        ->and($snapshot->map(fn (string $contents, string $file): bool => file_get_contents($app.'/'.$file) === $contents)->filter()->count())->toBe(11);

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

it('names only the header components a re-run added', function (): void {
    $app = starterKitApp('react');
    (new StarterKitInstaller($app, 'react'))->install('admin', 'admin');

    // A header from 0.1.7: everything but the language switcher.
    foreach (['app-sidebar-header.tsx', 'app-header.tsx'] as $file) {
        $path = $app.'/resources/js/components/'.$file;
        file_put_contents($path, (string) preg_replace('/^[ \t]*<AlfakitLanguageSwitcher \/>\n/m', '', str_replace('AlfakitLanguageSwitcher, ', '', (string) file_get_contents($path))));
    }

    // The first change of each file: the header's later ones are its classes and direction.
    $changes = collect((new StarterKitInstaller($app, 'react'))->install('admin', 'admin'))->groupBy('path')->map->first();

    expect($changes['resources/js/components/app-sidebar-header.tsx']['status'])->toBe('updated')
        ->and($changes['resources/js/components/app-sidebar-header.tsx']['note'])->toBe('added <AlfakitLanguageSwitcher /> to the header')
        ->and($changes['resources/js/components/app-header.tsx']['note'])->toBe('added <AlfakitLanguageSwitcher /> before the user menu')
        ->and(StarterKitInstaller::addedComponents('<X />', "<AlfakitSearch />\n<AlfakitNotifications />\n<AlfakitCopilot />\n"))->toBe('<AlfakitSearch />, <AlfakitNotifications /> and <AlfakitCopilot />');

    (new Filesystem)->deleteDirectory($app);
});

it('leaves a kit component it does not recognise alone, with a note', function (): void {
    expect(StarterKitInstaller::patchLogicalClasses('<button class="absolute end-2 p-1" />', 'vue'))->toBeNull()
        ->and(StarterKitInstaller::patchLogicalClasses('<input class={cn(\'pe-10\', className)} />', 'react'))->toBe('<input class={cn(\'pe-10\', className)} />');

    $app = starterKitApp('react');
    $input = $app.'/resources/js/components/password-input.tsx';
    file_put_contents($input, 'export default function PasswordInput() { return <input className="pl-3" /> }');

    $changes = collect((new StarterKitInstaller($app, 'react'))->install())->keyBy('path');

    expect($changes['resources/js/components/password-input.tsx']['status'])->toBe('skipped')
        ->and($changes['resources/js/components/password-input.tsx']['note'])->toContain('yourself')
        ->and(file_get_contents($input))->toBe('export default function PasswordInput() { return <input className="pl-3" /> }');

    (new Filesystem)->deleteDirectory($app);
});

it('adds the header widgets to a header layout whose classes are already logical', function (): void {
    $app = starterKitApp('vue');
    (new StarterKitInstaller($app, 'vue'))->install('admin', 'admin', headerWidgets: false);
    $header = (string) file_get_contents($app.'/resources/js/components/AppHeader.vue');

    expect($header)->toContain('ms-auto flex items-center space-x-2')->not->toContain('<AlfakitSearch');

    (new StarterKitInstaller($app, 'vue'))->install('admin', 'admin');

    expect(file_get_contents($app.'/resources/js/components/AppHeader.vue'))->toContain('<AlfakitSearch />');

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

it('leaves the dashboard alone with --no-dashboard-widgets or an unknown shape', function (): void {
    $app = starterKitApp('vue');
    $dashboard = (string) file_get_contents($app.'/resources/js/pages/Dashboard.vue');

    $changes = collect((new StarterKitInstaller($app, 'vue'))->install('admin', 'admin', dashboardWidgets: false))->keyBy('path');

    expect($changes)->not->toHaveKey('resources/js/pages/Dashboard.vue')
        ->and(file_get_contents($app.'/resources/js/pages/Dashboard.vue'))->toBe($dashboard)
        ->and(StarterKitInstaller::addWidgetsToDashboard('<template><MyCharts /></template>', 'vue'))->toBeNull();

    file_put_contents($app.'/resources/js/pages/Dashboard.vue', '<template><MyCharts /></template>');

    $changes = collect((new StarterKitInstaller($app, 'vue', force: true))->install('admin', 'admin'))->keyBy('path');

    expect($changes['resources/js/pages/Dashboard.vue']['status'])->toBe('skipped')
        ->and($changes['resources/js/pages/Dashboard.vue']['note'])->toContain('yourself');

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
        ->and(file_get_contents($app.'/resources/js/components/AppSidebarHeader.vue'))->toContain('<AlfakitSearch />')
        ->and(file_get_contents($app.'/resources/js/pages/Dashboard.vue'))->toContain('<AlfakitWidgets>');

    $vue = starterKitApp('vue');
    file_put_contents($vue.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    $this->app->setBasePath($vue);

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true, '--no-header-widgets' => true, '--no-dashboard-widgets' => true])->assertSuccessful();

    expect(file_get_contents($vue.'/resources/js/components/AppSidebarHeader.vue'))->not->toContain('AlfakitSearch')
        ->and(file_get_contents($vue.'/resources/js/pages/Dashboard.vue'))->not->toContain('AlfakitWidgets');

    (new Filesystem)->deleteDirectory($app);
    (new Filesystem)->deleteDirectory($vue);
});

it('gives a header set up before the language switcher existed <AlfakitLanguageSwitcher /> when the installer runs again', function (): void {
    $app = starterKitApp('react');
    (new Filesystem)->ensureDirectoryExists($app.'/vendor/composer');
    (new Filesystem)->ensureDirectoryExists($app.'/public');
    file_put_contents($app.'/package.json', json_encode(['dependencies' => ['@inertiajs/react' => '^3.0']]));
    $original = $this->app->basePath();
    $this->app->setBasePath($app);
    $header = $app.'/'.StarterKitInstaller::files('react')['sidebarHeader'];

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])->assertSuccessful();

    // A header block from an earlier version: no language switcher.
    $installed = (string) file_get_contents($header);
    file_put_contents($header, (string) preg_replace('/^[ \t]*<AlfakitLanguageSwitcher \/>\n/m', '', str_replace('AlfakitLanguageSwitcher, ', '', $installed)));

    // alfakit:upgrade (after composer update) says how to get it, and changes nothing itself.
    $this->artisan('alfakit:upgrade')
        ->expectsOutputToContain('has no <AlfakitLanguageSwitcher /> yet')
        ->assertSuccessful();

    expect(file_get_contents($header))->not->toContain('AlfakitLanguageSwitcher');

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])
        ->expectsOutputToContain(StarterKitInstaller::files('react')['sidebarHeader'])
        ->assertSuccessful();

    expect(file_get_contents($header))->toBe($installed);

    $this->artisan('alfakit:upgrade')
        ->doesntExpectOutputToContain('AlfakitLanguageSwitcher')
        ->assertSuccessful();

    $this->app->setBasePath($original);
    (new Filesystem)->deleteDirectory($app);
});

it('reminds an app set up before <AlfakitUserMenuItems /> existed, and adds it when the installer runs again', function (): void {
    $app = starterKitApp('vue');
    (new Filesystem)->ensureDirectoryExists($app.'/vendor/composer');
    (new Filesystem)->ensureDirectoryExists($app.'/public');
    file_put_contents($app.'/package.json', json_encode(['dependencies' => ['@inertiajs/vue3' => '^3.0']]));
    $original = $this->app->basePath();
    $this->app->setBasePath($app);
    $menu = $app.'/'.StarterKitInstaller::files('vue')['userMenu'];
    $before = (string) file_get_contents($menu);

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])->assertSuccessful();
    $installed = (string) file_get_contents($menu);

    // An app installed by an earlier version: its user menu is the kit's own.
    file_put_contents($menu, $before);

    $this->artisan('alfakit:upgrade')
        ->expectsOutputToContain('has no <AlfakitUserMenuItems /> yet')
        ->assertSuccessful();

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])->assertSuccessful();

    expect(file_get_contents($menu))->toBe($installed);

    $this->artisan('alfakit:upgrade')
        ->doesntExpectOutputToContain('AlfakitUserMenuItems')
        ->assertSuccessful();

    $this->app->setBasePath($original);
    (new Filesystem)->deleteDirectory($app);
});

it('renders the plugin assets in the starter kit root view, once, before @vite', function (): void {
    $view = "<head>\n        @viteReactRefresh\n        @vite(['resources/css/app.css'])\n    </head>\n";
    $updated = (string) StarterKitInstaller::addAssetsToRootView($view);

    expect($updated)->toContain('Assets::renderTags(alfakit()->getCurrentPanel()?->getId()')
        ->and(strpos($updated, 'Assets::renderTags('))->toBeLessThan(strpos($updated, '@viteReactRefresh'))
        ->and(StarterKitInstaller::addAssetsToRootView($updated))->toBe($updated)
        ->and(StarterKitInstaller::addAssetsToRootView('<head></head>'))->toBeNull();
});

it('adds <html dir> to the starter kit root view, once, after lang', function (): void {
    $dir = 'dir="{{ '.StarterKitInstaller::DIRECTION_CALL.' }}"';
    $html = '<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}" @class([\'dark\' => ($appearance ?? \'system\') == \'dark\'])>';
    $view = "<!DOCTYPE html>\n{$html}\n    <head>\n";
    $updated = (string) StarterKitInstaller::addDirectionToRootView($view);

    expect($updated)->toBe("<!DOCTYPE html>\n{{-- ".StarterKitInstaller::DIRECTION_MARKER.": <html dir> follows the panel language (php artisan alfakit:install --starter-kit) --}}\n"
        .'<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}" '.$dir.' @class([\'dark\' => ($appearance ?? \'system\') == \'dark\'])>'."\n    <head>\n")
        ->and(StarterKitInstaller::addDirectionToRootView($updated))->toBe($updated)
        // Without lang: right after the tag name.
        ->and(StarterKitInstaller::addDirectionToRootView("<html>\n<head>"))->toContain('<html '.$dir.'>')
        // An app that sets dir itself, or has no <html>, is left alone (and told so).
        ->and(StarterKitInstaller::addDirectionToRootView("<html lang=\"en\" dir=\"{{ \$dir }}\">\n<head>"))->toBeNull()
        ->and(StarterKitInstaller::addDirectionToRootView('<div></div>'))->toBeNull();
});

it('leaves a sidebar that sets its own side alone, with a note', function (): void {
    expect(StarterKitInstaller::addDirectionToSidebar("<script setup lang=\"ts\">\nimport { Sidebar } from '@/components/ui/sidebar';\n</script>\n<template><Sidebar side=\"right\" /></template>", 'vue'))->toBeNull()
        ->and(StarterKitInstaller::addDirectionToSidebar('export const AppSidebar = () => <Sidebar />', 'react'))->toBeNull();

    $app = starterKitApp('react');
    $sidebar = $app.'/resources/js/components/app-sidebar.tsx';
    file_put_contents($sidebar, str_replace('variant="inset">', 'variant="inset" side="right">', (string) file_get_contents($sidebar)));

    $changes = collect((new StarterKitInstaller($app, 'react'))->install())->where('path', 'resources/js/components/app-sidebar.tsx')->values();

    // The navigation still goes in; the side is the app's.
    expect($changes[0]['status'])->toBe('updated')
        ->and($changes[1]['status'])->toBe('skipped')
        ->and($changes[1]['note'])->toContain('yourself')
        ->and(file_get_contents($sidebar))->not->toContain('useAlfakitDirection')->toContain('<AlfakitNav />');

    (new Filesystem)->deleteDirectory($app);
});

it('reminds an app set up before the page followed the panel language direction, and adds it when the installer runs again', function (string $kit, string $inertia): void {
    $app = starterKitApp($kit);
    (new Filesystem)->ensureDirectoryExists($app.'/vendor/composer');
    (new Filesystem)->ensureDirectoryExists($app.'/public');
    file_put_contents($app.'/package.json', json_encode(['dependencies' => [$inertia => '^3.0']]));
    $original = $this->app->basePath();
    $this->app->setBasePath($app);
    $view = $app.'/resources/views/app.blade.php';
    $sidebar = $app.'/'.StarterKitInstaller::files($kit)['sidebar'];
    $viewBefore = (string) file_get_contents($view);
    $sidebarBefore = (string) file_get_contents($sidebar);

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])->assertSuccessful();

    $installedView = (string) file_get_contents($view);
    $installedSidebar = (string) file_get_contents($sidebar);

    // An app installed by an earlier version: the kit's own <html> and <Sidebar>.
    file_put_contents($view, (string) StarterKitInstaller::addAssetsToRootView($viewBefore));
    file_put_contents($sidebar, (string) StarterKitInstaller::addNavToSidebar($sidebarBefore, $kit));

    $this->artisan('alfakit:upgrade')
        ->expectsOutputToContain('does not follow the panel language\'s direction yet')
        ->assertSuccessful();

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])->assertSuccessful();

    expect(file_get_contents($view))->toBe($installedView)
        ->and(file_get_contents($sidebar))->toBe($installedSidebar);

    $this->artisan('alfakit:upgrade')
        ->doesntExpectOutputToContain('direction yet')
        ->assertSuccessful();

    // An app installed by 0.2.2: <html dir> and the sidebar, but the kit's own header layout
    // and password field (physical classes, the mobile menu on the left).
    $header = $app.'/'.StarterKitInstaller::files($kit)['header'];
    $password = $app.'/'.collect(StarterKitInstaller::logicalClassFiles($kit))->first(fn (string $file): bool => str_contains(strtolower($file), 'password'));
    $fixtures = dirname(__DIR__, 2).'/Fixtures/StarterKits/'.$kit.'/';
    $installedHeader = (string) file_get_contents($header);
    $installedPassword = (string) file_get_contents($password);
    file_put_contents($password, (string) file_get_contents($fixtures.substr($password, strlen($app) + 1)));
    file_put_contents($header, (string) StarterKitInstaller::addWidgetsToHeader((string) file_get_contents($fixtures.StarterKitInstaller::files($kit)['header']), $kit, sidebarLayout: false));

    $this->artisan('alfakit:upgrade')
        ->expectsOutputToContain('Your starter kit app ('.StarterKitInstaller::files($kit)['header'])
        ->assertSuccessful();

    $this->artisan('alfakit:install', ['--starter-kit' => true, '--no-npm' => true, '--no-composer-script' => true])->assertSuccessful();

    expect(file_get_contents($header))->toBe($installedHeader)
        ->and(file_get_contents($password))->toBe($installedPassword);

    $this->artisan('alfakit:upgrade')
        ->doesntExpectOutputToContain('direction yet')
        ->assertSuccessful();

    $this->app->setBasePath($original);
    (new Filesystem)->deleteDirectory($app);
})->with([
    'react' => ['react', '@inertiajs/react'],
    'vue' => ['vue', '@inertiajs/vue3'],
    'svelte' => ['svelte', '@inertiajs/svelte'],
]);
