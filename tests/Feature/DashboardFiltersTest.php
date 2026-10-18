<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\FilteredDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\SalesDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

/*
 * F18: dashboard filters shared by widgets, and several dashboards per panel.
 */

function dashboardsPanel(): Panel
{
    return Panels::register('boards', fn (Panel $panel): Panel => $panel->pages([FilteredDashboard::class, SalesDashboard::class]));
}

function widgetStats(array $props): array
{
    $widgets = collect($props['page']['content'])->firstWhere('type', 'Widgets');

    return array_column($widgets['widgets'][0]['stats'], 'value', 'label');
}

it('gives every dashboard its own route, navigation item and widgets', function (): void {
    dashboardsPanel();
    $this->actingAs(User::query()->create(['name' => 'D', 'email' => 'd@example.com', 'password' => 'secret']));

    expect(FilteredDashboard::getRoutePath())->toBe('/')
        ->and(SalesDashboard::getRoutePath())->toBe('/sales-dashboard')
        ->and(Dashboard::getRoutePath())->toBe('/')
        ->and(SalesDashboard::getNavigationLabel())->toBe('Sales dashboard')
        ->and(alfakit()->getPanel('boards')->getUrl())->toBe('http://localhost/boards');

    $main = inertiaProps(inertiaGet($this, '/boards'));
    $sales = inertiaProps(inertiaGet($this, '/boards/sales-dashboard'));

    expect(widgetStats($main))->toHaveKey('Posts')
        ->and(widgetStats($sales))->toBe(['Sales' => 42])
        ->and($sales['page']['title'])->toBe('Sales dashboard')
        ->and(array_column($main['alfakit']['navigation'][0]['items'], 'url'))->toBe(['http://localhost/boards', 'http://localhost/boards/sales-dashboard']);
});

it('applies dashboard filters to widgets and keeps them in the session', function (): void {
    dashboardsPanel();
    $this->actingAs(User::query()->create(['name' => 'D', 'email' => 'd2@example.com', 'password' => 'secret']));
    Post::query()->create(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft']);
    Post::query()->create(['title' => 'Live', 'slug' => 'live', 'status' => 'published']);
    Post::query()->create(['title' => 'Live 2', 'slug' => 'live-2', 'status' => 'published']);

    $props = inertiaProps(inertiaGet($this, '/boards'));
    $form = collect($props['page']['content'])->firstWhere('type', 'Form');

    expect(widgetStats($props))->toBe(['Posts' => 3, 'Filter' => 'all'])
        ->and(array_column($form['actions'], 'name'))->toBe(['applyFilters']);

    // Values are validated like any form.
    inertiaPost($this, '/boards/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'applyFilters', 'data' => ['status' => 'deleted']])
        ->assertJsonValidationErrors('status');

    inertiaPost($this, '/boards/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'applyFilters', 'data' => ['status' => 'published', 'search' => 'x']])
        ->assertRedirect();

    $props = inertiaProps(inertiaGet($this, '/boards'));
    $form = collect($props['page']['content'])->firstWhere('type', 'Form');

    expect(widgetStats($props))->toBe(['Posts' => 2, 'Filter' => 'published'])
        ->and($form['state']['status'])->toBe('published')
        ->and(array_column($form['actions'], 'name'))->toBe(['applyFilters', 'resetFilters']);

    inertiaPost($this, '/boards/_alfakit/actions', ['token' => $props['page']['token'], 'action' => 'resetFilters'])->assertRedirect();

    expect(widgetStats(inertiaProps(inertiaGet($this, '/boards'))))->toBe(['Posts' => 3, 'Filter' => 'all']);
});
