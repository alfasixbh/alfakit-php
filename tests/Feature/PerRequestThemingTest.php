<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Auth\Pages\Login;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\CreatePost;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\ListPosts;
use AlfaSix\Alfakit\Theming\Density;
use AlfaSix\Alfakit\Theming\Layout;
use AlfaSix\Alfakit\Theming\Themes;

/*
 * Theme, design dimensions, density and the Vite stylesheet may be closures that run once
 * per request with the user, tenant, page and route injected: one panel, a different look
 * per role or page.
 */

function resetPerRequestTheme(): void
{
    $panel = alfakit()->getPanel('admin')->theme(null)->sidebarVariant(null)->density(null)->radius(null)->font(null)->headingFont(null)->viteTheme(null)->topNavigation(false);
    (fn () => $this->designOverrides = [])->call($panel);
    $panel->flushThemeCache();
}

function themingAdmin(): User
{
    return User::query()->create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password']);
}

function themingEditor(): User
{
    return User::query()->create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => 'password']);
}

beforeEach(fn () => resetPerRequestTheme());

afterEach(function (): void {
    resetPerRequestTheme();
    Themes::flush();
});

it('switches the layout per role through the HTML attributes and the shared prop', function (): void {
    alfakit()->getPanel('admin')->design(
        layout: fn (?User $user): string => $user?->email === 'admin@example.com' ? 'sidebar' : 'topbar',
        nav: fn ($user) => $user === null ? 'bar' : 'pill',
    );

    $this->actingAs(themingAdmin());
    $html = $this->get('/admin/posts')->assertOk();
    $html->assertSee('data-layout="sidebar"', false)->assertSee('data-nav="pill"', false);
    expect(inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['theme']['design'])->toMatchArray(['layout' => 'sidebar', 'nav' => 'pill']);

    auth()->logout();
    $this->actingAs(themingEditor());
    $this->get('/admin/posts')->assertOk()->assertSee('data-layout="topbar"', false)->assertSee('data-nav="pill"', false);
    expect(inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['theme']['design']['layout'])->toBe('topbar');

    auth()->logout();
    $this->get('/admin/login')->assertOk()->assertSee('data-layout="topbar"', false)->assertSee('data-nav="bar"', false);
});

it('resolves the theme, density and stylesheet per page and per request', function (): void {
    $panel = alfakit()->getPanel('admin')
        ->theme(fn (?string $page): string => $page === CreatePost::class ? 'minimal' : 'neutral')
        ->density(fn (?string $page): Density => $page === ListPosts::class ? Density::Compact : Density::Comfortable)
        ->viteTheme(fn (Panel $panel, ?string $page): string => $page === Login::class ? 'resources/css/guest.css' : 'resources/css/admin.css');

    $this->actingAs(themingAdmin());

    $create = inertiaProps(inertiaGet($this, '/admin/posts/create'))['alfakit']['theme'];
    $list = inertiaProps(inertiaGet($this, '/admin/posts'))['alfakit']['theme'];

    expect($create['name'])->toBe('minimal')
        ->and($create['design']['layout'])->toBe('topbar')
        ->and($list['name'])->toBe('neutral')
        ->and($list['density'])->toBe('compact')
        ->and($create['density'])->toBe('comfortable');

    // The stylesheet follows the page of the latest request.
    expect($panel->getViteEntries()[0])->toBe('resources/css/admin.css');

    auth()->logout();
    $this->get('/admin/login')->assertOk();

    expect($panel->getViteEntries()[0])->toBe('resources/css/guest.css');
});

it('evaluates a theme closure once per request and again on the next one', function (): void {
    $calls = 0;

    $panel = alfakit()->getPanel('admin')->design(layout: function () use (&$calls): Layout {
        $calls++;

        return Layout::Rail;
    });

    $panel->getDesign();
    $panel->getHtmlThemeAttributes();
    $panel->getThemeSchema();

    expect($calls)->toBe(1)
        ->and($panel->getDesign()['layout'])->toBe('rail');

    $this->actingAs(themingAdmin());
    $this->get('/admin/posts')->assertOk()->assertSee('data-layout="rail"', false);

    // The root view, the shared prop and the Vite entries of that request all shared one evaluation.
    expect($calls)->toBe(2);

    $panel->layout('sidebar');

    expect($panel->getDesign()['layout'])->toBe('sidebar');
});

it('validates closure results when they are evaluated', function (): void {
    alfakit()->getPanel('admin')->design(inputs: fn () => 'glossy')->getDesign();
})->throws(InvalidArgumentException::class, 'is not a valid inputs');
