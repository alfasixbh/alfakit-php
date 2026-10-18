<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\CreatePost;
use AlfaSix\Alfakit\Theming\Density;
use AlfaSix\Alfakit\Theming\FontProvider;
use AlfaSix\Alfakit\Theming\InputStyle;
use AlfaSix\Alfakit\Theming\SidebarVariant;
use AlfaSix\Alfakit\Theming\Slot;
use AlfaSix\Alfakit\Theming\Theme;
use AlfaSix\Alfakit\Theming\Themes;
use Illuminate\Support\HtmlString;

function resetPanelTheme(): void
{
    $panel = alfakit()->getPanel('admin')->theme(null)->sidebarVariant(null)->density(null)->radius(null)->font(null)->headingFont(null)->viteTheme(null)->topNavigation(false);
    (fn () => $this->designOverrides = [])->call($panel);
}

beforeEach(fn () => resetPanelTheme());

afterEach(function (): void {
    resetPanelTheme();
    Themes::flush();
});

it('ships six themes with their own layout defaults', function (): void {
    expect(array_keys(Themes::all()))->toBe(['neutral', 'alfakit', 'minimal', 'soft', 'corporate', 'filament']);

    $panel = alfakit()->getPanel('admin');

    expect($panel->getTheme()->getName())->toBe('neutral')
        ->and($panel->getSidebarVariant())->toBe(SidebarVariant::Inset);

    $panel->theme('filament');

    expect($panel->getSidebarVariant())->toBe(SidebarVariant::Flush)
        ->and($panel->getFont())->toBe('Inter')
        ->and($panel->getRadius())->toBe('0.75rem')
        ->and($panel->getFontUrl())->toBe('https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap');

    $panel->theme('minimal');

    expect($panel->getDensity())->toBe(Density::Compact)
        ->and($panel->getFontUrl())->toBeNull()
        ->and($panel->getFontCssValue())->toBe('system-ui');
});

it('lets the panel override what the theme chose', function (): void {
    $panel = alfakit()->getPanel('admin')
        ->theme('corporate')
        ->sidebarVariant('floating')
        ->density(Density::Comfortable)
        ->radius('0')
        ->font('Source Sans 3', FontProvider::Google);

    expect($panel->getSidebarVariant())->toBe(SidebarVariant::Floating)
        ->and($panel->getDensity())->toBe(Density::Comfortable)
        ->and($panel->getRadius())->toBe('0')
        ->and($panel->getFontUrl())->toBe('https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;500;600;700&display=swap');
});

it('supports custom themes built on a built-in', function (): void {
    Themes::register(Theme::make('acme')->extends('alfakit')->font('Sora')->colors(['primary' => '#ff5500', 'info' => '#0ea5e9']));

    $panel = alfakit()->getPanel('admin')->theme('acme');

    // The panel's own colours (primary #2563eb in the fixture) win over the theme's.
    expect($panel->getThemeAttribute())->toBe('alfakit acme')
        ->and($panel->getSidebarVariant())->toBe(SidebarVariant::Floating)
        ->and($panel->getFont())->toBe('Sora')
        ->and($panel->getThemeColors())->toMatchArray(['primary' => '#2563eb', 'info' => '#0ea5e9']);
});

it('rejects unknown themes with a helpful message', function (): void {
    alfakit()->getPanel('admin')->theme('nope')->getTheme();
})->throws(InvalidArgumentException::class, 'Available: neutral, alfakit');

it('themes the document before the first paint', function (): void {
    alfakit()->getPanel('admin')->theme('alfakit');

    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('data-panel="admin"', false)
        ->assertSee('data-alfakit-theme="alfakit"', false)
        ->assertSee('data-sidebar-variant="floating"', false)
        ->assertSee('data-density="comfortable"', false)
        ->assertSee('--alfakit-font: &#039;Inter&#039;', false)
        ->assertSee('https://fonts.bunny.net/css?family=inter', false)
        ->assertSee('https://fonts.bunny.net/css?family=montserrat', false);

    $theme = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['theme'];

    expect($theme)->toMatchArray(['name' => 'alfakit', 'sidebar' => 'floating', 'radius' => '0.875rem', 'fontCss' => "'Inter'"]);
});

it('renders slot content, scoped to pages or resources', function (): void {
    $panel = alfakit()->getPanel('admin');
    $panel->slot(Slot::TOPBAR_END, new HtmlString('<span>v2</span>'));
    $panel->slot(Slot::SIDEBAR_FOOTER, fn () => Text::make('Support: help@acme.test'));
    $panel->slot(Slot::PAGE_HEADER_AFTER, '<p>Only on create</p>', scopes: [CreatePost::class]);
    $panel->slot(Slot::CONTENT_END, '<p>Posts only</p>', scopes: [PostResource::class]);
    $panel->slot(Slot::FOOTER, fn () => null);

    $login = inertiaProps(inertiaGet($this, '/admin/login'))['alfakit']['slots'];

    expect($login[Slot::TOPBAR_END])->toBe([['type' => 'html', 'html' => '<span>v2</span>']])
        ->and($login[Slot::SIDEBAR_FOOTER][0]['type'])->toBe('component')
        ->and($login[Slot::SIDEBAR_FOOTER][0]['component']['type'])->toBe('Text')
        ->and($login)->not->toHaveKeys([Slot::PAGE_HEADER_AFTER, Slot::CONTENT_END])
        // The footer only holds the default "Developed by Alfa Six" credit.
        ->and($login[Slot::FOOTER])->toHaveCount(1)
        ->and($login[Slot::FOOTER][0]['html'])->toContain('data-slot="credit"');

    $this->actingAs(admin());
    $create = inertiaProps(inertiaGet($this, '/admin/posts/create'))['alfakit']['slots'];
    $list = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['slots'];

    expect($create[Slot::PAGE_HEADER_AFTER][0]['html'])->toBe('<p>Only on create</p>')
        ->and($create[Slot::CONTENT_END][0]['html'])->toBe('<p>Posts only</p>')
        ->and($list)->not->toHaveKey(Slot::PAGE_HEADER_AFTER)
        ->and($list[Slot::CONTENT_END][0]['html'])->toBe('<p>Posts only</p>');
});

it('can give a panel its own stylesheet', function (): void {
    $panel = alfakit()->getPanel('admin')->viteTheme('resources/css/admin.css');

    expect($panel->getViteEntries())->toBe(['resources/css/admin.css', config('alfakit.inertia.vite.js')]);
});

it('gives every theme its own structure, not just colours', function (): void {
    $panel = alfakit()->getPanel('admin');
    $designs = [];

    foreach (array_keys(Themes::all()) as $name) {
        $designs[$name] = $panel->theme($name)->getDesign();
    }

    expect($designs['minimal']['layout'])->toBe('topbar')
        ->and($designs['soft']['layout'])->toBe('rail')
        ->and($designs['alfakit'])->toMatchArray(['nav' => 'pill', 'header' => 'banner', 'surfaces' => 'elevated', 'inputs' => 'filled', 'buttons' => 'pill'])
        ->and($designs['corporate'])->toMatchArray(['nav' => 'bar', 'header' => 'band', 'tables' => 'striped', 'buttons' => 'square'])
        ->and(count(array_unique(array_map('serialize', $designs))))->toBe(6);

    $panel->theme('soft');

    expect($panel->getHeadingFont())->toBe('Fraunces')
        ->and($panel->getFontUrls())->toBe([
            'https://fonts.bunny.net/css?family=nunito:400,500,600,700&display=swap',
            'https://fonts.bunny.net/css?family=fraunces:400,500,600,700&display=swap',
        ]);
});

it('lets panels override single design dimensions', function (): void {
    $panel = alfakit()->getPanel('admin')->theme('alfakit')->design(layout: 'topbar', inputs: InputStyle::Outline);

    expect($panel->getDesign())->toMatchArray(['layout' => 'topbar', 'inputs' => 'outline', 'nav' => 'pill', 'header' => 'banner'])
        ->and($panel->getHtmlThemeAttributes())->toMatchArray(['data-layout' => 'topbar', 'data-inputs' => 'outline', 'data-nav' => 'pill']);

    alfakit()->getPanel('admin')->theme('neutral')->topNavigation();

    expect(alfakit()->getPanel('admin')->getDesign()['layout'])->toBe('topbar');
});

it('validates design values', function (): void {
    alfakit()->getPanel('admin')->design(nav: 'zigzag');
})->throws(InvalidArgumentException::class, 'Use: soft, pill, bar, underline');

it('translates the built-in theme names and descriptions, and resolves closure labels', function (): void {
    expect(Themes::get('filament')->getLabel())->toBe('Filament style')
        ->and(Themes::get('neutral')->getDescription())->toStartWith('The classic');

    Illuminate\Support\Facades\Lang::addLines(['panel.themes.filament.label' => 'Filament-Stil', 'panel.themes.neutral.description' => 'Der Klassiker.'], 'de', 'alfakit');
    app()->setLocale('de');

    // Resolved when read, so the static theme registry follows the request's language.
    expect(Themes::get('filament')->getLabel())->toBe('Filament-Stil')
        ->and(Themes::get('neutral')->getDescription())->toBe('Der Klassiker.')
        ->and(Theme::make('acme')->label(fn (): string => app()->getLocale() === 'de' ? 'Akme' : 'Acme')->getLabel())->toBe('Akme')
        ->and(Theme::make('plain')->getLabel())->toBe('Plain');

    app()->setLocale('en');
});
