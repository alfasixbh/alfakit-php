<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Commands\Concerns\CanGenerateFiles;
use AlfaSix\Alfakit\Panel;

/*
 * Generators put a panel's classes where the panel discovers them, so adding a second
 * panel never moves the first panel's classes; a panel that isn't registered yet gets its
 * own namespace when the app has another panel.
 */

function namespaceSuffix(string $panelId): string
{
    $generator = new class
    {
        use CanGenerateFiles;

        public function suffix(string $id): string
        {
            return $this->panelNamespaceSuffix($id);
        }
    };

    return $generator->suffix($panelId);
}

it('follows the namespace a panel discovers its resources in', function (): void {
    alfakit()->registerPanel(Panel::make()->id('first')->path('first')
        ->discoverResources(in: app_path('Alfakit/Resources'), for: 'App\\Alfakit\\Resources'));
    alfakit()->registerPanel(Panel::make()->id('shop')->path('shop')
        ->discoverResources(in: app_path('Alfakit/Shop/Resources'), for: 'App\\Alfakit\\Shop\\Resources'));

    expect(namespaceSuffix('first'))->toBe('')
        ->and(namespaceSuffix('shop'))->toBe('\\Shop')
        // `make:alfakit-panel portal`: not registered yet, other panels exist.
        ->and(namespaceSuffix('portal'))->toBe('\\Portal');
});

it('makes only the first panel the default one', function (): void {
    $sandbox = sys_get_temp_dir().'/alfakit-panels-'.uniqid();
    $original = app()->basePath();
    (new Illuminate\Filesystem\Filesystem)->ensureDirectoryExists("{$sandbox}/bootstrap");
    file_put_contents("{$sandbox}/bootstrap/providers.php", "<?php\n\nreturn [\n];\n");
    app()->setBasePath($sandbox);

    try {
        $default = collect(alfakit()->getPanels())->first(fn (Panel $panel): bool => $panel->isDefault());

        $this->artisan('make:alfakit-panel', ['id' => 'portal'])->assertSuccessful();
        $portal = (string) file_get_contents("{$sandbox}/app/Providers/Alfakit/PortalPanelProvider.php");

        expect($default)->not->toBeNull()
            ->and($portal)->not->toContain('->default()')
            ->toContain("app_path('Alfakit/Portal/Resources')");
    } finally {
        app()->setBasePath($original);
        (new Illuminate\Filesystem\Filesystem)->deleteDirectory($sandbox);
    }
});

it('registers providers the way bootstrap/providers.php lists them', function (): void {
    $kit = "<?php\n\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\FortifyServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n    FortifyServiceProvider::class,\n];\n";
    $plain = "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n";
    $editor = AlfaSix\Alfakit\Commands\Support\PanelProviderEditor::class;

    expect($editor::addBootstrapProvider($kit, 'App\\Providers\\Alfakit\\AdminPanelProvider'))
        ->toBe("<?php\n\nuse App\\Providers\\Alfakit\\AdminPanelProvider;\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\FortifyServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n    FortifyServiceProvider::class,\n    AdminPanelProvider::class,\n];\n")
        ->and($editor::addBootstrapProvider($plain, 'App\\Providers\\Alfakit\\AdminPanelProvider'))
        ->toContain("    App\\Providers\\Alfakit\\AdminPanelProvider::class,\n];")
        ->and($editor::addBootstrapProvider((string) $editor::addBootstrapProvider($kit, 'App\\Providers\\Alfakit\\AdminPanelProvider'), 'App\\Providers\\Alfakit\\AdminPanelProvider'))
        ->toBe($editor::addBootstrapProvider($kit, 'App\\Providers\\Alfakit\\AdminPanelProvider'));
});
