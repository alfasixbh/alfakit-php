<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Navigation\NavigationBuilder;
use AlfaSix\Alfakit\Navigation\NavigationItem;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\UserResource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/*
 * Filament 5.1-5.10.2 navigation: dynamic navigation(), static setters, parent items by
 * key, extra attributes, Htmlable labels and `$badge` injection.
 */

class Nav5xReportsPage extends Page
{
    protected static ?string $slug = 'reports';

    protected static ?string $navigationLabel = 'Reports';
}

class Nav5xAuditPage extends Page
{
    protected static ?string $slug = 'audit';

    protected static ?string $navigationLabel = 'Audit';
}

beforeEach(function (): void {
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();
    Gate::before(static fn (): bool => true);
    $this->actingAs(MfaUser::query()->create(['name' => 'Nav', 'email' => 'nav'.uniqid().'@example.com', 'password' => 'password']));
});

afterEach(function (): void {
    Page::flushNavigationOverrides();
});

function nav5x(mixed $test, string $id, Closure $configure): array
{
    Panels::register($id, fn (Panel $panel): Panel => $configure($panel->login()->pages([Dashboard::class, Nav5xReportsPage::class, Nav5xAuditPage::class])->resources([UserResource::class])));

    return inertiaProps(inertiaGet($test, "/{$id}"))['alfakit']['navigation'];
}

/**
 * @return list<string>
 */
function nav5xLabels(array $navigation): array
{
    return collect($navigation)->flatMap(static fn (array $group): array => $group['items'])->pluck('label')->all();
}

function nav5xItem(array $navigation, string $label): ?array
{
    return collect($navigation)->flatMap(static fn (array $group): array => $group['items'])->firstWhere('label', $label);
}

it('keeps the auto-discovered navigation when a navigation() closure returns true', function (): void {
    expect(nav5xLabels(nav5x($this, 'navtrue', fn (Panel $panel): Panel => $panel->navigation(fn (): bool => true))))
        ->toContain('Reports')->toContain(UserResource::getNavigationLabel());

    expect(nav5x($this, 'navfalse', fn (Panel $panel): Panel => $panel->navigation(fn (): bool => false)))->toBe([]);

    $panel = alfakit()->getPanel('navfalse');
    expect($panel->hasNavigation())->toBeFalse()
        ->and($panel->hasCustomNavigation())->toBeFalse();
});

it('builds the navigation from a closure that returns a NavigationBuilder', function (): void {
    $navigation = nav5x($this, 'navbuilder', fn (Panel $panel): Panel => $panel->navigation(
        fn (NavigationBuilder $builder): NavigationBuilder => $builder->items([NavigationItem::make('Only')->url('/only')]),
    ));

    expect(nav5xLabels($navigation))->toBe(['Only'])
        ->and(alfakit()->getPanel('navbuilder')->hasCustomNavigation())->toBeTrue();
});

it('overrides navigation properties with static setters, per class', function (): void {
    Nav5xReportsPage::navigationLabel('Insights');
    Nav5xReportsPage::navigationGroup('Analytics');
    Nav5xReportsPage::navigationSort(3);
    Nav5xReportsPage::navigationIcon('chart-bar');
    UserResource::navigationLabel('People');

    $navigation = nav5x($this, 'navsetters', fn (Panel $panel): Panel => $panel);
    $analytics = collect($navigation)->firstWhere('label', 'Analytics');

    expect($analytics['items'][0]['label'])->toBe('Insights')
        ->and($analytics['items'][0]['icon'])->not->toBeNull()
        ->and(Nav5xReportsPage::getNavigationSort())->toBe(3)
        ->and(nav5xLabels($navigation))->toContain('People')->toContain('Audit')
        // Siblings sharing Page's static properties are not affected.
        ->and(Nav5xAuditPage::getNavigationLabel())->toBe('Audit')
        ->and(Nav5xAuditPage::getNavigationGroup())->toBeNull();
});

it('nests items under a parent named by class or by label, sorted', function (): void {
    Nav5xAuditPage::navigationParentItem(UserResource::class);

    $navigation = nav5x($this, 'navparents', fn (Panel $panel): Panel => $panel->navigationItems([
        NavigationItem::make('Login history')->parentItem(UserResource::getNavigationLabel())->sort(5)->url('/history'),
        NavigationItem::make('Invites')->parentItem(UserResource::class)->sort(1)->url('/invites'),
        NavigationItem::make('Keyed')->key('keyed-parent')->url('/keyed'),
        NavigationItem::make('Child of key')->parentItem('keyed-parent')->url('/child'),
    ]));

    $users = nav5xItem($navigation, UserResource::getNavigationLabel());

    expect(array_column($users['children'], 'label'))->toBe(['Audit', 'Invites', 'Login history'])
        ->and(array_column(nav5xItem($navigation, 'Keyed')['children'], 'label'))->toBe(['Child of key'])
        ->and(nav5xLabels($navigation))->not->toContain('Audit');
});

it('serialises extra attributes of navigation items without event handlers', function (): void {
    $navigation = nav5x($this, 'navattrs', fn (Panel $panel): Panel => $panel->navigationItems([
        NavigationItem::make('Docs')->url('/docs')->extraAttributes(['class' => 'text-primary', 'data-tour' => 'docs', 'onclick' => 'alert(1)']),
    ]));

    expect(nav5xItem($navigation, 'Docs')['extraAttributes'])->toBe(['class' => 'text-primary', 'data-tour' => 'docs']);
});

it('sends sanitised Htmlable navigation labels with a labelHtml flag', function (): void {
    $navigation = nav5x($this, 'navhtml', fn (Panel $panel): Panel => $panel->navigationItems([
        NavigationItem::make(new HtmlString('Inbox <b>new</b><script>alert(1)</script>'))->url('/inbox'),
        NavigationItem::make('<i>Plain</i>')->url('/plain'),
    ]));

    $items = collect($navigation)->flatMap(static fn (array $group): array => $group['items']);
    $inbox = $items->first(static fn (array $item): bool => str_starts_with($item['label'], 'Inbox'));
    $plain = $items->firstWhere('url', '/plain');

    expect($inbox['labelHtml'])->toBeTrue()
        ->and($inbox['label'])->toContain('<b>new</b>')->not->toContain('script')
        ->and($plain)->not->toHaveKey('labelHtml')
        ->and($plain['label'])->toBe('<i>Plain</i>')
        ->and(NavigationItem::make(new HtmlString('Inbox <b>new</b>'))->getLabel())->toBe('Inbox new');
});

it('passes the badge value to badge colour and tooltip closures', function (): void {
    $item = NavigationItem::make('Orders')->url('/orders')
        ->badge(fn (): int => 12)
        ->badgeColor(fn (int $badge): string => $badge > 10 ? 'danger' : 'success')
        ->badgeTooltip(fn (int $badge): string => "{$badge} open");

    expect($item->toSchema())->toMatchArray(['badge' => 12, 'badgeColor' => 'danger', 'badgeTooltip' => '12 open']);
});
