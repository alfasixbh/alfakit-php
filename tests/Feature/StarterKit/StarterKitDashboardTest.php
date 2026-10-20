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

    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($base);
});
