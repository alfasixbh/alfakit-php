<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Navigation\MenuItem;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\ActionLifecycle\LifecyclePage;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\FakeBillingProvider;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Tenancy\ProjectResource;

class NavigationParityBreadcrumbPage extends Page
{
    protected static ?string $breadcrumb = 'Reports';
}

class NavigationParityPlainPage extends Page {}

it('uses $breadcrumb as the breadcrumb of a custom page', function (): void {
    expect(app(NavigationParityBreadcrumbPage::class)->getBreadcrumbs())->toBe(['Reports'])
        ->and(app(NavigationParityBreadcrumbPage::class)->getBreadcrumb())->toBe('Reports');
});

it('keeps custom pages without $breadcrumb free of breadcrumbs', function (): void {
    expect(app(NavigationParityPlainPage::class)->getBreadcrumbs())->toBe([]);
});

it('groups tenant menu items with nested arrays, like the user menu', function (): void {
    Panels::register('teamgroups', fn (Panel $panel): Panel => $panel
        ->login()
        ->tenant(Team::class, slugAttribute: 'slug')
        ->tenantBillingProvider(new FakeBillingProvider)
        ->resources([ProjectResource::class])
        ->pages([Dashboard::class, LifecyclePage::class])
        ->tenantMenuItems([
            Action::make('settings')->label('Settings')->url('/settings'),
            [
                Action::make('invite')->label('Invite')->url('/invite'),
                MenuItem::make()->label('Members')->url('/members'),
            ],
            [
                'billing' => fn (Action $action): Action => $action->label('Plans'),
                Action::make('hidden')->label('Hidden')->url('/hidden')->hidden(),
                Action::make('audit')->label('Audit log')->url('/audit'),
            ],
        ]));

    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']);
    $team = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha-'.uniqid()]);
    $user->teams()->attach([$team->id]);
    $this->actingAs($user);

    $menu = inertiaProps(inertiaGet($this, "/teamgroups/{$team->slug}/lifecycle"))['alfakit']['tenancy']['menu'];

    expect(array_map(static fn (array $item): array => [$item['label'], $item['group'] ?? 0], $menu))->toBe([
        ['Settings', 0],
        ['Invite', 1],
        ['Members', 1],
        ['Audit log', 2],
        ['Plans', 0],
    ]);
});

class NavigationParityReportsPage extends Page
{
    protected static ?string $routePath = '/reports/{period}';

    public string $period = '';

    public function mount(mixed ...$parameters): void
    {
        $this->period = (string) ($parameters[0] ?? '');
    }

    public function getSubheading(): ?string
    {
        return "Period: {$this->period}";
    }
}

it('leaves a page whose route needs parameters out of the navigation instead of crashing every page', function (): void {
    Panels::register('routeparams', fn (Panel $panel): Panel => $panel
        ->login()
        ->pages([Dashboard::class, NavigationParityReportsPage::class]));

    $this->actingAs(TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'secret']));

    $dashboard = inertiaGet($this, '/routeparams');
    $dashboard->assertOk();
    $labels = collect(inertiaProps($dashboard)['alfakit']['navigation'] ?? [])->flatten()->filter(fn ($v) => is_string($v))->all();

    expect(NavigationParityReportsPage::hasRequiredRouteParameters())->toBeTrue()
        ->and(Dashboard::hasRequiredRouteParameters())->toBeFalse()
        ->and(implode(' ', $labels))->not->toContain('Navigation parity reports page');

    $page = inertiaGet($this, '/routeparams/reports/month');
    $page->assertOk();
    expect(json_encode(inertiaProps($page)))->toContain('Period: month');
});
