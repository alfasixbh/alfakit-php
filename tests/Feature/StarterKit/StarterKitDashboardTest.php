<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Support\StarterKitNavigation;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Widgets\PostStatsWidget;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 * Starter kit mode: the panel's dashboard widgets on the host's own dashboard
 * (`<AlfakitWidgets />`), shared as the `alfakitWidgets` prop on `starterKitDashboard()`
 * pages and served lazily by the panel's components endpoint.
 */

function starterKitDashboardPanel(string $id, ?Closure $configure = null): Panel
{
    $panel = Panels::register($id, fn (Panel $panel): Panel => ($configure ?? fn (Panel $panel): Panel => $panel)($panel->starterKit()->widgets([PostStatsWidget::class])));

    StarterKitNavigation::register(alfakit());

    return $panel;
}

function starterKitDashboardUser(): User
{
    return User::query()->create(['name' => 'Ann', 'email' => 'ann'.uniqid().'@example.com', 'password' => 'secret']);
}

beforeEach(function (): void {
    Route::middleware('web')->get('/login', fn () => 'login')->name('login');
    Route::middleware('web')->get('/dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    Route::middleware('web')->get('/settings/profile', fn () => Inertia::render('settings/profile'))->name('profile.edit');
    app('router')->getRoutes()->refreshNameLookups();
});

it('shares the panel widgets with the host dashboard and serves them lazily', function (): void {
    starterKitDashboardPanel('kitd');
    Post::query()->create(['title' => 'One', 'slug' => 'one-'.uniqid(), 'status' => 'draft']);
    $this->actingAs(starterKitDashboardUser());

    $entries = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'];
    $component = $entries[0]['component'];
    $widget = $component['content'][0]['widgets'][0];

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['id'])->toBe('kitd')
        ->and($component['alfakit'])->toBe('standalone')
        ->and($component['content'][0]['type'])->toBe('Widgets')
        ->and($component['content'][0]['columns'])->toBe(['default' => 1, 'lg' => 2])
        ->and($widget['lazy'])->toBeTrue()
        ->and($component['lazy'])->toBe(["widget:{$widget['id']}"])
        // Lazy widgets, polling and actions go through the panel's own endpoints.
        ->and($component['endpoints']['components'])->toBe('http://localhost/kitd/_alfakit/standalone/components')
        ->and($component['endpoints']['action'])->toBe('http://localhost/kitd/_alfakit/actions');

    $fresh = $this->getJson('/kitd/_alfakit/standalone/components?'.http_build_query(['token' => $component['token'], 'targets' => "widget:{$widget['id']}"]))
        ->assertOk()
        ->json();

    expect($fresh["widget:{$widget['id']}"]['stats'][0]['label'])->toBe('Posts')
        ->and($fresh["widget:{$widget['id']}"]['stats'][0]['value'])->toBe(Post::query()->count());

    // The token is the user's, and only for this panel.
    $this->actingAs(starterKitDashboardUser());
    $this->getJson('/kitd/_alfakit/standalone/components?'.http_build_query(['token' => $component['token'], 'targets' => "widget:{$widget['id']}"]))->assertForbidden();
});

it('shares nothing on other pages, for guests or for users who may not open the panel', function (): void {
    starterKitDashboardPanel('kite');

    // Guests.
    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'])->toBeNull();

    // Other host pages.
    $this->actingAs(starterKitDashboardUser());
    expect(inertiaProps(inertiaGet($this, '/settings/profile'))['alfakitWidgets'])->toBeNull()
        ->and(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'])->toHaveCount(1);

    // `canAccessPanel()` false.
    $this->actingAs(MfaUser::query()->create(['name' => 'Bob', 'email' => 'bob'.uniqid().'@example.com', 'password' => 'secret', 'role' => 'banned']));
    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'])->toBeNull();
});

it('leaves the widgets off while a plugin hides them (Billing for users who must subscribe first)', function (): void {
    $state = new ArrayObject(['hidden' => true]);
    starterKitDashboardPanel('kitw', fn (Panel $panel): Panel => $panel->hideStarterKitDashboardWhen(fn (): bool => $state['hidden']));
    $this->actingAs(starterKitDashboardUser());

    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'])->toBeNull();

    $state['hidden'] = false;
    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'])->toHaveCount(1);
});

it('follows starterKitDashboard() and leaves out panels without widgets', function (): void {
    starterKitDashboardPanel('kitf', fn (Panel $panel): Panel => $panel->starterKitDashboard('profile.*'));
    $this->actingAs(starterKitDashboardUser());

    expect(inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'])->toBeNull()
        ->and(inertiaProps(inertiaGet($this, '/settings/profile'))['alfakitWidgets'][0]['id'])->toBe('kitf');

    alfakit()->getPanel('kitf')->starterKitDashboard(false);
    expect(inertiaProps(inertiaGet($this, '/settings/profile'))['alfakitWidgets'])->toBeNull();

    Panels::register('kitg', fn (Panel $panel): Panel => $panel->starterKit());
    alfakit()->getPanel('kitf')->starterKitDashboard();
    $entries = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'];

    expect(array_column($entries, 'id'))->toBe(['kitf']);
});

it('tells where a starter kit panel widget appears', function (): void {
    starterKitDashboardPanel('kith');
    $this->app->setBasePath($base = sys_get_temp_dir().'/alfakit-widget-hint-'.uniqid());

    $this->artisan('make:alfakit-widget', ['name' => 'BlogStats', '--type' => 'stats', '--panel' => 'kith'])
        ->expectsOutputToContain("It appears on your app's dashboard (/dashboard) through <AlfakitWidgets />")
        ->assertSuccessful();

    $this->artisan('make:alfakit-widget', ['name' => 'OtherStats', '--type' => 'stats', '--panel' => 'admin'])
        ->doesntExpectOutputToContain('<AlfakitWidgets />')
        ->assertSuccessful();

    // A custom widget's component is not loaded by the kit's dashboard page (W-S3-04).
    $this->artisan('make:alfakit-widget', ['name' => 'BlogStats', '--type' => 'stats', '--panel' => 'kith', '--force' => true])
        ->doesntExpectOutputToContain('pass the component there')
        ->assertSuccessful();

    $this->artisan('make:alfakit-widget', ['name' => 'Welcome', '--type' => 'custom', '--panel' => 'kith', '--adapter' => 'svelte'])
        ->expectsOutputToContain("add `import Welcome from '@/alfakit/widgets/Welcome.svelte'` to resources/js/pages/Dashboard.svelte and render <AlfakitWidgets components={{ Welcome }}>")
        ->assertSuccessful();

    $this->artisan('make:alfakit-widget', ['name' => 'Hello', '--type' => 'custom', '--panel' => 'kith', '--adapter' => 'vue'])
        ->expectsOutputToContain("add `import Hello from '@/alfakit/widgets/Hello.vue'` to resources/js/pages/Dashboard.vue and render <AlfakitWidgets :components=\"{ Hello }\">")
        ->assertSuccessful();

    $this->artisan('make:alfakit-widget', ['name' => 'Hi', '--type' => 'custom', '--panel' => 'kith', '--adapter' => 'react'])
        ->expectsOutputToContain("add `import Hi from '@/alfakit/widgets/Hi'` to resources/js/pages/dashboard.tsx and render <AlfakitWidgets components={{ Hi }}>")
        ->assertSuccessful();

    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($base);
});

it('builds the shared widgets and navigation in the panel language while the host page keeps its own', function (): void {
    starterKitDashboardPanel('kitl', fn (Panel $panel): Panel => $panel->locales(['en', 'ar']));
    Route::middleware('web')->get('/dashboard-locale', fn () => Inertia::render('dashboard', [
        'appLocale' => fn (): string => app()->getLocale(),
    ]))->name('dashboard.locale');
    app('router')->getRoutes()->refreshNameLookups();
    alfakit()->getPanel('kitl')->starterKitDashboard(['dashboard', 'dashboard.locale']);
    $this->actingAs(starterKitDashboardUser());

    $props = inertiaProps($this->withHeaders(['Accept-Language' => 'ar'])->get('/dashboard-locale', ['X-Inertia' => 'true', 'X-Inertia-Version' => '']));
    $localization = $props['alfakitWidgets'][0]['component']['localization'];

    // Arabic and right to left like the panel's own pages and header components...
    expect($localization['direction'])->toBe('rtl')
        ->and($props['alfakitNavigation'][0]['id'])->toBe('kitl')
        // ...while the host's own page (its props, its translations) stays in the app language.
        ->and($props['appLocale'])->toBe('en')
        ->and(app()->getLocale())->toBe('en');
});

it("offers the customizable dashboard's editor on the host dashboard and saves the panel dashboard's layout (W-38)", function (): void {
    (require __DIR__.'/../../../database/migrations/0001_01_01_000200_create_alfakit_dashboard_layouts_table.php')->up();
    Panels::register('kitc', fn (Panel $panel): Panel => $panel->starterKit()
        ->pages([AlfaSix\Alfakit\Tests\Fixtures\Customizable\CustomDashboard::class])
        ->customizableDashboards()
        ->availableDashboardWidgets([AlfaSix\Alfakit\Tests\Fixtures\Customizable\LibraryChart::class]));
    StarterKitNavigation::register(alfakit());
    $this->actingAs(starterKitDashboardUser());
    [$a, $b, $library] = [
        AlfaSix\Alfakit\Widgets\Widget::idFromClass(AlfaSix\Alfakit\Tests\Fixtures\Customizable\StatsA::class),
        AlfaSix\Alfakit\Widgets\Widget::idFromClass(AlfaSix\Alfakit\Tests\Fixtures\Customizable\StatsB::class),
        AlfaSix\Alfakit\Widgets\Widget::idFromClass(AlfaSix\Alfakit\Tests\Fixtures\Customizable\LibraryChart::class),
    ];

    $component = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'][0]['component'];
    $block = $component['content'][0];

    expect($component['content'])->toHaveCount(1)
        ->and($block['type'])->toBe('Widgets')
        ->and(array_column($block['widgets'], 'id'))->toBe([$a, $b])
        ->and($block['customize']['endpoint'])->toBe('http://localhost/kitc/_alfakit/dashboard/layout')
        ->and(array_column($block['customize']['library'], 'id'))->toBe([$a, $b, $library]);

    // "Edit dashboard" saves the panel dashboard's layout with its own token.
    $this->postJson('/kitc/_alfakit/dashboard/layout', ['token' => $block['customize']['token'], 'layout' => [
        ['id' => $library, 'span' => 2],
        ['id' => $b],
        ['id' => $a, 'hidden' => true],
    ]])->assertOk()->assertJsonPath('customized', true);

    $component = inertiaProps(inertiaGet($this, '/dashboard'))['alfakitWidgets'][0]['component'];
    $block = $component['content'][0];

    expect(array_column($block['widgets'], 'id'))->toBe([$library, $b])
        ->and($block['widgets'][0]['lazy'])->toBeTrue()
        ->and($block['customize']['customized'])->toBeTrue()
        ->and($component['lazy'])->toBe(["widget:{$library}"]);

    // The added library widget loads lazily through the host dashboard's token, in the user's span.
    $fresh = $this->getJson('/kitc/_alfakit/standalone/components?'.http_build_query(['token' => $component['token'], 'targets' => "widget:{$library}"]))
        ->assertOk()
        ->json("widget:{$library}");

    expect($fresh['stats'][0]['label'])->toBe('Library')
        ->and($fresh['columnSpan'])->toBe(['default' => 'full', 'lg' => 'full']);
});
