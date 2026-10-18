<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Dashboard\Layouts\DashboardLayout;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Customizable\CustomDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\Customizable\LibraryChart;
use AlfaSix\Alfakit\Tests\Fixtures\Customizable\SecretWidget;
use AlfaSix\Alfakit\Tests\Fixtures\Customizable\StatsA;
use AlfaSix\Alfakit\Tests\Fixtures\Customizable\StatsB;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Widgets\Widget;

/*
 * Personal dashboards: `customizableDashboards()` / `Dashboard::customizable()`.
 */

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/0001_01_01_000200_create_alfakit_dashboard_layouts_table.php')->up();
    SecretWidget::$visible = false;
});

function customizablePanel(string $id = 'mine', bool $tenancy = false): Panel
{
    return Panels::register($id, function (Panel $panel) use ($tenancy): Panel {
        $panel->pages([CustomDashboard::class])
            ->customizableDashboards()
            ->availableDashboardWidgets([LibraryChart::class, SecretWidget::class]);

        return $tenancy ? $panel->tenant(Team::class, slugAttribute: 'slug') : $panel;
    });
}

function dashboardBlock(array $props): array
{
    return collect($props['page']['content'])->firstWhere('type', 'Widgets');
}

function dashboardUser(string $email = 'dash@example.com'): User
{
    return User::query()->create(['name' => 'Dash', 'email' => $email, 'password' => 'secret']);
}

it('serialises the default layout and a library filtered by canView()', function (): void {
    customizablePanel();
    $this->actingAs(dashboardUser());

    $block = dashboardBlock(inertiaProps(inertiaGet($this, '/mine')));
    $a = Widget::idFromClass(StatsA::class);
    $b = Widget::idFromClass(StatsB::class);
    $library = collect($block['customize']['library'])->keyBy('id');

    expect(array_column($block['widgets'], 'id'))->toBe([$a, $b])
        ->and($block['customize']['customized'])->toBeFalse()
        ->and($block['customize']['endpoint'])->toBe('http://localhost/mine/_alfakit/dashboard/layout')
        ->and($block['customize']['token'])->toBeString()
        ->and(array_column($block['customize']['defaultLayout'], 'id'))->toBe([$a, $b])
        ->and($library->keys()->all())->toBe([$a, $b, Widget::idFromClass(LibraryChart::class)])
        ->and($library[Widget::idFromClass(LibraryChart::class)]['label'])->toBe('Library widget')
        ->and($library[Widget::idFromClass(LibraryChart::class)]['default'])->toBeFalse()
        ->and($library[$b]['label'])->toBe('Second stats')
        // `$columnSpanOptions = [1, 'full']` on a two-column grid.
        ->and(array_column($library[$b]['spans'], 'value'))->toBe([1, 2])
        ->and($library[$b]['spans'][1]['columnSpan'])->toBe(['default' => 'full', 'lg' => 'full'])
        ->and($library[$a]['spans'][0]['columnSpan'])->toBe(['default' => 'full', 'lg' => 1]);
});

it('saves the order, spans, hidden widgets and library widgets per user', function (): void {
    customizablePanel();
    $user = dashboardUser();
    $this->actingAs($user);

    $props = inertiaProps(inertiaGet($this, '/mine'));
    $token = dashboardBlock($props)['customize']['token'];
    [$a, $b, $library] = [Widget::idFromClass(StatsA::class), Widget::idFromClass(StatsB::class), Widget::idFromClass(LibraryChart::class)];

    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [
        ['id' => $library, 'span' => 2],
        ['id' => $b, 'span' => 'full'],
        ['id' => $a, 'hidden' => true],
    ]])->assertOk()->assertJsonPath('customized', true);

    $block = dashboardBlock(inertiaProps(inertiaGet($this, '/mine')));

    expect(array_column($block['widgets'], 'id'))->toBe([$library, $b])
        ->and($block['widgets'][0]['columnSpan'])->toBe(['default' => 'full', 'lg' => 'full'])
        ->and($block['customize']['customized'])->toBeTrue()
        ->and($block['customize']['layout'])->toBe([
            ['id' => $library, 'span' => 2, 'hidden' => false],
            ['id' => $b, 'span' => 2, 'hidden' => false],
            ['id' => $a, 'span' => null, 'hidden' => true],
        ]);

    // Another user still sees the developer's default.
    $this->actingAs(dashboardUser('other@example.com'));

    expect(array_column(dashboardBlock(inertiaProps(inertiaGet($this, '/mine')))['widgets'], 'id'))->toBe([$a, $b]);

    // Reset to default.
    $this->actingAs($user);
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'reset' => true])->assertOk()->assertJsonPath('customized', false);

    expect(DashboardLayout::query()->count())->toBe(0)
        ->and(array_column(dashboardBlock(inertiaProps(inertiaGet($this, '/mine')))['widgets'], 'id'))->toBe([$a, $b]);
});

it('refuses widgets outside the library or hidden by canView()', function (): void {
    customizablePanel();
    $this->actingAs(dashboardUser());

    $token = dashboardBlock(inertiaProps(inertiaGet($this, '/mine')))['customize']['token'];

    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [['id' => Widget::idFromClass(SecretWidget::class)]]])
        ->assertStatus(422);
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [['id' => 'app-widgets-not-registered']]])
        ->assertStatus(422);
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => 'nope'])
        ->assertStatus(422);

    expect(DashboardLayout::query()->count())->toBe(0);

    // Spans outside the widget's options fall back to its own span.
    $b = Widget::idFromClass(StatsB::class);
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [['id' => $b, 'span' => 7]]])->assertOk();

    expect(DashboardLayout::query()->first()->layout[0])->toBe(['id' => $b, 'span' => null, 'hidden' => false]);

    // A widget that stopped being viewable disappears from a stored layout.
    SecretWidget::$visible = true;
    $secret = Widget::idFromClass(SecretWidget::class);
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [['id' => $secret]]])->assertOk();
    SecretWidget::$visible = false;

    expect(array_column(dashboardBlock(inertiaProps(inertiaGet($this, '/mine')))['widgets'], 'id'))->not->toContain($secret);
});

it('authorises the layout endpoint from the context token', function (): void {
    customizablePanel();
    $owner = dashboardUser();
    $this->actingAs($owner);

    $token = dashboardBlock(inertiaProps(inertiaGet($this, '/mine')))['customize']['token'];

    // Without a token, with a forged one, or as another user.
    $this->postJson('/mine/_alfakit/dashboard/layout', ['layout' => []])->assertStatus(400);
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => 'forged', 'layout' => []])->assertStatus(400);

    $this->actingAs(dashboardUser('intruder@example.com'));
    $this->postJson('/mine/_alfakit/dashboard/layout', ['token' => $token, 'layout' => []])->assertForbidden();

    expect(DashboardLayout::query()->count())->toBe(0);
});

it('keeps dashboards that are not customizable as they are', function (): void {
    Panels::register('plain', fn (Panel $panel): Panel => $panel->pages([CustomDashboard::class]));
    $this->actingAs(dashboardUser());

    $props = inertiaProps(inertiaGet($this, '/plain'));
    $block = dashboardBlock($props);

    expect($block)->not->toHaveKey('customize');

    $this->postJson('/plain/_alfakit/dashboard/layout', ['token' => $props['page']['token'], 'layout' => []])->assertForbidden();

    // `Dashboard::customizable()` turns it on for one dashboard class.
    CustomDashboard::customizable();

    try {
        expect(dashboardBlock(inertiaProps(inertiaGet($this, '/plain'))))->toHaveKey('customize');
        CustomDashboard::customizable(false);
        // `false` wins over the panel's `customizableDashboards()`.
        customizablePanel('off');
        expect(dashboardBlock(inertiaProps(inertiaGet($this, '/off'))))->not->toHaveKey('customize');
    } finally {
        CustomDashboard::customizable(null);
    }

    expect(dashboardBlock(inertiaProps(inertiaGet($this, '/plain'))))->not->toHaveKey('customize');
});

it('stores layouts per tenant', function (): void {
    customizablePanel('teams', tenancy: true);

    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $user->teams()->attach([$alpha->id, $beta->id]);
    $this->actingAs($user);

    $token = dashboardBlock(inertiaProps(inertiaGet($this, '/teams/alpha')))['customize']['token'];
    [$a, $b] = [Widget::idFromClass(StatsA::class), Widget::idFromClass(StatsB::class)];

    $this->postJson('/teams/alpha/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [['id' => $b], ['id' => $a]]])->assertOk();

    // Replaying alpha's token under beta's URL is refused.
    $this->postJson('/teams/beta/_alfakit/dashboard/layout', ['token' => $token, 'layout' => [['id' => $a, 'hidden' => true]]])->assertNotFound();

    expect(array_column(dashboardBlock(inertiaProps(inertiaGet($this, '/teams/alpha')))['widgets'], 'id'))->toBe([$b, $a])
        ->and(array_column(dashboardBlock(inertiaProps(inertiaGet($this, '/teams/beta')))['widgets'], 'id'))->toBe([$a, $b])
        ->and(DashboardLayout::query()->first()->tenant_key)->toBe((string) $alpha->id);
});
