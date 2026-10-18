<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Icons\Heroicon;
use AlfaSix\Alfakit\Support\Icons\HeroiconMap;
use AlfaSix\Alfakit\Support\Icons\SvgSanitizer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\HtmlString;

/*
 * Heroicons, icon sets, inline SVG and URL icons, and the alias catalogue (`Icons`).
 */

afterEach(fn () => Icons::flush());

it('maps every Heroicon to a Lucide icon', function (): void {
    expect(Heroicon::cases())->toHaveCount(648)
        ->and(HeroiconMap::MAP)->toHaveCount(324);

    foreach (Heroicon::cases() as $case) {
        expect(HeroiconMap::has($case->getName()))->toBeTrue($case->name)
            ->and($case->getLucideName())->toMatch('/^[a-z0-9-]+$/');
    }

    expect(Heroicon::OutlinedUser->getIconName())->toBe('heroicon-o-user')
        ->and(Heroicon::User->getIconName())->toBe('heroicon-s-user')
        ->and(Icons::resolve(Heroicon::OutlinedUser))->toBe('user')
        ->and(Icons::resolve(Heroicon::Cog6Tooth))->toBe('settings')
        ->and(Icons::resolve(Heroicon::OutlinedMagnifyingGlass))->toBe('search')
        ->and(Icons::resolve('heroicon-m-x-mark'))->toBe('x')
        ->and(Icons::resolve('heroicon-o-arrow-trending-up'))->toBe('trending-up')
        ->and(Icons::resolve('heroicon-o-not-a-heroicon'))->toBe(HeroiconMap::FALLBACK)
        ->and(Icons::resolve('users'))->toBe('users');
});

it('resolves icons returned by closures on components', function (): void {
    $item = NavigationItem::make('Users')->url('/users')->icon(fn (): Heroicon => Heroicon::OutlinedUsers);

    expect($item->getIcon())->toBe('users');
});

it('renders a registered icon set through a closure or a directory', function (): void {
    Icons::registerSet('brand', fn (string $name): ?string => $name === 'logo' ? '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>' : null);

    expect(Icons::resolve('brand-logo'))->toBe('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>')
        ->and(Icons::resolve('brand-missing'))->toBe('brand-missing');

    $directory = sys_get_temp_dir().'/alfakit-icons-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($directory);
    file_put_contents($directory.'/o-user.svg', '<svg viewBox="0 0 24 24"><path d="M1 1"/></svg>');

    try {
        // blade-heroicons layout: `o-user.svg` for `heroicon-o-user`; the set wins over the Lucide mapping.
        Icons::registerSet('heroicon-o', $directory);

        expect(Icons::resolve(Heroicon::OutlinedUser))->toContain('<path d="M1 1"/>')
            ->and(Icons::resolve(Heroicon::User))->toBe('user')
            ->and(Icons::resolve('heroicon-o-../../etc/passwd'))->toBe('heroicon-o-../../etc/passwd');
    } finally {
        (new Filesystem)->deleteDirectory($directory);
    }
});

it('rejects invalid set prefixes', function (): void {
    Icons::registerSet('bad prefix', fn (): null => null);
})->throws(InvalidArgumentException::class);

it('sanitises inline SVG icons', function (): void {
    $svg = Icons::resolve(new HtmlString(
        '<?xml version="1.0"?><svg viewBox="0 0 24 24" onload="alert(1)" style="fill:red">'
        .'<script>alert(1)</script><foreignObject><div>x</div></foreignObject>'
        .'<a href="javascript:alert(1)"><path d="M0 0"/></a>'
        .'<path d="M1 1" fill="url(https://evil.test/#x)" onclick="x()"/>'
        .'<use href="https://evil.test/sprite.svg#a"/><use href="#local"/>'
        .'<style>@import url(https://evil.test)</style></svg>',
    ));

    expect($svg)
        ->toStartWith('<svg')
        ->toContain('<path d="M1 1"/>')
        ->toContain('<use href="#local"/>')
        ->toContain('style="fill:red"')
        ->not->toContain('script')
        ->not->toContain('onload')
        ->not->toContain('onclick')
        ->not->toContain('foreignObject')
        ->not->toContain('javascript')
        ->not->toContain('evil.test')
        ->not->toContain('<style');

    // Plain strings with markup are sanitised too; non-SVG markup is dropped.
    expect(Icons::resolve('<svg><script>x</script></svg>'))->toBe('<svg xmlns="http://www.w3.org/2000/svg"/>')
        ->and(Icons::resolve('<img src=x onerror=alert(1)>'))->toBeNull()
        ->and(SvgSanitizer::sanitize('<!DOCTYPE svg [<!ENTITY x "y">]><svg>&x;</svg>'))->toBeNull();
});

it('passes image URLs through and drops unsafe ones', function (): void {
    expect(Icons::resolve('https://cdn.test/logo.svg'))->toBe('https://cdn.test/logo.svg')
        ->and(Icons::resolve('/images/logo.png'))->toBe('/images/logo.png')
        ->and(Icons::resolve('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='))->toBe('data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=')
        ->and(Icons::isUrl('javascript:alert(1)'))->toBeFalse()
        ->and(Icons::isUrl('users'))->toBeFalse();
});

it('accepts Filament alias names and enum values when registering', function (): void {
    Icons::register([
        'panels::sidebar.collapse-button' => Heroicon::OutlinedChevronDoubleLeft,
        'alfakit::actions.delete' => 'https://cdn.test/trash.svg',
        'users' => 'user-round',
    ]);

    expect(Icons::get('alfakit::panels.sidebar.collapse-button'))->toBe('chevrons-left')
        ->and(Icons::get('panels::sidebar.collapse-button'))->toBe('chevrons-left')
        ->and(Icons::get('alfakit::actions.delete'))->toBe('https://cdn.test/trash.svg')
        ->and(Icons::resolve('users'))->toBe('user-round')
        ->and(Icons::get('alfakit::tables.search-field'))->toBe('search')
        ->and(Icons::get('alfakit::unknown', 'circle'))->toBe('circle');
});

it('stops on alias cycles', function (): void {
    Icons::register(['a-icon' => 'b-icon', 'b-icon' => 'a-icon']);

    expect(Icons::resolve('a-icon'))->toBeString();
});

it('ships a catalogue of aliases mapped to known icons', function (): void {
    expect(count(Icons::DEFAULTS))->toBeGreaterThan(140);

    foreach (Icons::DEFAULTS as $alias => $icon) {
        expect($alias)->toStartWith('alfakit::')
            ->and($icon)->toMatch('/^[a-z0-9-]+$/');
    }
});

it('shares replaced aliases with the renderer chrome', function (): void {
    expect(Panel::make('icons')->getThemeSchema()['icons'])->toBeNull();

    Icons::register([
        'alfakit::panels.global-search.field' => Heroicon::OutlinedMagnifyingGlassCircle,
        'pencil' => 'pen',
    ]);

    expect(Panel::make('icons')->getThemeSchema()['icons'])->toBe(['alfakit::panels.global-search.field' => 'search']);
});

it('accepts Heroicon cases and inline SVG in icon setters directly', function (): void {
    $heroicon = Heroicon::OutlinedUser;
    $svg = new HtmlString('<svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>');

    expect(AlfaSix\Alfakit\Forms\Components\TextInput::make('a')->prefixIcon($heroicon)->getPrefixIcon())->toBe('user')
        ->and(AlfaSix\Alfakit\Forms\Components\Toggle::make('b')->onIcon($heroicon)->getOnIcon())->toBe('user')
        ->and(AlfaSix\Alfakit\Schemas\Components\Section::make('S')->icon($heroicon)->getIcon())->toBe('user')
        ->and(AlfaSix\Alfakit\Actions\Action::make('x')->modalIcon($heroicon)->getModalIcon())->toBe('user')
        ->and(AlfaSix\Alfakit\Schemas\Components\Section::make('S')->icon($svg)->getIcon())->toStartWith('<svg');
});

it('accepts Heroicon cases in navigation and menu icons', function (): void {
    $heroicon = Heroicon::OutlinedUser;

    expect(NavigationItem::make('Users')->icon($heroicon)->getIcon())->toBe('user')
        ->and(AlfaSix\Alfakit\Navigation\NavigationGroup::make('People')->icon($heroicon)->getIcon())->toBe('user')
        ->and(AlfaSix\Alfakit\Navigation\MenuItem::make()->icon($heroicon)->getIcon())->toBe('user');
});
