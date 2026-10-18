<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Migration\FilamentClassMap;
use AlfaSix\Alfakit\Migration\MigrationReport;
use AlfaSix\Alfakit\Migration\TokenRewriter;
use AlfaSix\Alfakit\Theming\Slot;

function rewriteRenderHooks(string $file, string $code): string
{
    $out = (new TokenRewriter(new MigrationReport(sys_get_temp_dir())))->rewrite($file, $code);

    $path = tempnam(sys_get_temp_dir(), 'hook').'.php';
    file_put_contents($path, $out);
    exec('php -l '.escapeshellarg($path), $lint, $status);
    unlink($path);

    expect($status)->toBe(0);

    return $out;
}

it('maps every render hook to an existing slot', function (): void {
    foreach (FilamentClassMap::renderHooks() as $class => $hooks) {
        foreach ($hooks as $hook => $slot) {
            expect(defined(Slot::class.'::'.$slot))->toBeTrue("{$class}::{$hook} maps to the missing Slot::{$slot}");
        }
    }

    expect(FilamentClassMap::slotFor('Filament\\View\\PanelsRenderHook', 'PAGE_HEADER_ACTIONS_BEFORE'))->toBe('PAGE_ACTIONS_BEFORE')
        ->and(FilamentClassMap::slotFor('\\Filament\\Tables\\View\\TablesRenderHook', 'TOOLBAR_SEARCH_AFTER'))->toBe('TABLE_TOOLBAR_SEARCH_AFTER')
        ->and(FilamentClassMap::slotFor('Filament\\View\\PanelsRenderHook', 'PAGE_SUB_NAVIGATION_SELECT_BEFORE'))->toBeNull();
});

it('turns panel render hooks into slots and drops hooks without one', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->renderHook(PanelsRenderHook::TOPBAR_END, fn (): string => 'v2')
            ->renderHook(TablesRenderHook::TOOLBAR_SEARCH_BEFORE, fn (): string => 'hint', scopes: ListOrders::class)
            ->renderHook(PanelsRenderHook::PAGE_SUB_NAVIGATION_SELECT_BEFORE, fn (): string => 'x');
    }
}
CODE;

    $out = rewriteRenderHooks('app/Providers/Filament/AdminPanelProvider.php', $code);

    // The dropped hook is marked for a manual decision; the code itself no longer has it.
    expect($out)->toContain('// TODO(alfakit-migration): `->renderHook(PanelsRenderHook::PAGE_SUB_NAVIGATION_SELECT_BEFORE');
    $out = (string) preg_replace('/^\s*\/\/ TODO\(alfakit-migration\).*\R/m', '', $out);

    expect($out)
        ->toContain("->slot(\\AlfaSix\\Alfakit\\Theming\\Slot::TOPBAR_END, fn (): string => 'v2')")
        ->toContain("->slot(\\AlfaSix\\Alfakit\\Theming\\Slot::TABLE_TOOLBAR_SEARCH_BEFORE, fn (): string => 'hint', scopes: ListOrders::class)")
        ->not->toContain('PAGE_SUB_NAVIGATION_SELECT_BEFORE')
        ->not->toContain('renderHook')
        ->not->toContain('use Filament\\View\\PanelsRenderHook');
});

it('turns FilamentView::registerRenderHook() into Slots::register()', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, fn (): string => '<script></script>');
    }
}
CODE;

    $out = rewriteRenderHooks('app/Providers/AppServiceProvider.php', $code);

    expect($out)
        ->toContain("\\AlfaSix\\Alfakit\\Theming\\Slots::register(\\AlfaSix\\Alfakit\\Theming\\Slot::BODY_END, fn (): string => '<script></script>');")
        ->not->toContain('FilamentView');
});

it('keeps the theme mode, avatar provider and global search panel options', function (): void {
    $code = <<<'CODE'
<?php

namespace App\Providers\Filament;

use Filament\AvatarProviders\UiAvatarsProvider;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Enums\ThemeMode;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->defaultThemeMode(ThemeMode::Dark)
            ->themeSwitcher(false)
            ->defaultAvatarProvider(UiAvatarsProvider::class)
            ->searchableTenantMenu()
            ->globalSearchResourceOptIn()
            ->globalSearchDebounce('750ms')
            ->globalSearchFieldKeyBindingSuffix();
    }
}
CODE;

    $out = rewriteRenderHooks('app/Providers/Filament/AdminPanelProvider.php', $code);

    expect($out)
        ->toContain('use AlfaSix\\Alfakit\\Theming\\ThemeMode;')
        ->toContain('use AlfaSix\\Alfakit\\AvatarProviders\\UiAvatarsProvider;')
        ->toContain('->defaultThemeMode(ThemeMode::Dark)')
        ->toContain('->themeSwitcher(false)')
        ->toContain('->defaultAvatarProvider(UiAvatarsProvider::class)')
        ->toContain('->searchableTenantMenu()')
        ->toContain('->globalSearchResourceOptIn()')
        ->toContain("->globalSearchDebounce('750ms')")
        ->toContain('->globalSearchFieldKeyBindingSuffix()');
});
