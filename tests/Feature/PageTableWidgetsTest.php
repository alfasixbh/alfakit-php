<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\GuardedWidget;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\LazyPostTableStats;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PageTableDashboard;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PageTablePostResource;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PageTableProjectResource;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PageTableReport;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PostTableStats;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\StatusCountWidget;
use AlfaSix\Alfakit\Widgets\Widget;

/*
 * Widgets reading the list page table (`InteractsWithPageTable`) and widget properties
 * passed through `Widget::make([...])`.
 */

function pageTablePanel(): Panel
{
    return Panels::register('pt', fn (Panel $panel): Panel => $panel
        ->resources([PageTablePostResource::class])
        ->pages([PageTableDashboard::class, PageTableReport::class]));
}

function pageTableSeed(): User
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);

    foreach ([
        ['Alpha', 'draft', false, 1],
        ['Beta', 'draft', false, 2],
        ['Gamma', 'published', true, 3],
        ['Delta', 'published', true, 4],
        ['Epsilon', 'archived', false, 5],
    ] as [$title, $status, $published, $rating]) {
        Post::query()->create(['title' => $title, 'slug' => strtolower($title), 'status' => $status, 'is_published' => $published, 'rating' => $rating, 'author_id' => $user->id]);
    }

    return $user;
}

/**
 * @param  array<string, mixed>  $props
 * @return array<string, array<string, mixed>>
 */
function pageTableWidgets(array $props): array
{
    $block = collect($props['page']['content'])->firstWhere('key', 'headerWidgets')
        ?? collect($props['page']['content'])->firstWhere('type', 'Widgets');

    return collect($block['widgets'])->keyBy('id')->all();
}

/**
 * @param  array<string, mixed>  $widget
 * @return array<string, mixed>
 */
function pageTableStats(array $widget): array
{
    return array_column($widget['stats'], 'value', 'label');
}

/**
 * @return array<string, mixed>
 */
function pageTablePartial(AlfaSix\Alfakit\Tests\TestCase $test, string $url, string $only, array $headers = []): array
{
    $component = inertiaGet($test, $url)->json('component');

    return inertiaProps($test->get($url, [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => $component,
        'X-Inertia-Partial-Data' => $only,
        ...$headers,
    ]));
}

it('gives widgets the list page table query and records', function (): void {
    pageTablePanel();
    $this->actingAs(pageTableSeed());

    $widgets = pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-posts')));
    $stats = pageTableStats($widgets[Widget::idFromClass(PostTableStats::class)]);

    // Five posts, two per table page.
    expect($stats)->toBe(['Matching' => 5, 'On page' => 2, 'Total' => 5, 'Rating' => 15]);
});

it('follows the active tab, filters and search', function (): void {
    pageTablePanel();
    $this->actingAs(pageTableSeed());
    $id = Widget::idFromClass(PostTableStats::class);

    $tab = pageTableStats(pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-posts?activeTab=published')))[$id]);
    $filter = pageTableStats(pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-posts?filters[status][value]=draft')))[$id]);
    $search = pageTableStats(pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-posts?search=lta')))[$id]);
    $both = pageTableStats(pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-posts?activeTab=published&filters[status][value]=draft')))[$id]);

    expect($tab)->toBe(['Matching' => 2, 'On page' => 2, 'Total' => 2, 'Rating' => 7])
        ->and($filter)->toBe(['Matching' => 2, 'On page' => 2, 'Total' => 2, 'Rating' => 3])
        ->and($search)->toBe(['Matching' => 1, 'On page' => 1, 'Total' => 1, 'Rating' => 4])
        ->and($both)->toBe(['Matching' => 0, 'On page' => 0, 'Total' => 0, 'Rating' => 0]);
});

it('keeps the table state when the widget polls or loads lazily', function (): void {
    pageTablePanel();
    $this->actingAs(pageTableSeed());
    $id = Widget::idFromClass(PostTableStats::class);
    $lazyId = Widget::idFromClass(LazyPostTableStats::class);
    $url = '/pt/pt-posts?activeTab=published&search=Gam';

    $polled = pageTablePartial($this, $url, Page::POLL_PROP, [Page::POLL_HEADER => "widget:{$id}"]);
    $lazy = pageTablePartial($this, $url, Page::LAZY_PROP);

    expect(pageTableStats($polled[Page::POLL_PROP]["widget:{$id}"]))->toBe(['Matching' => 1, 'On page' => 1, 'Total' => 1, 'Rating' => 3])
        ->and(pageTableStats($lazy[Page::LAZY_PROP]["widget:{$lazyId}"]))->toBe(['Lazy matching' => 1]);
});

it('scopes the page table query to the current tenant', function (): void {
    Panels::register('ptt', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->resources([PageTableProjectResource::class]));

    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $user->teams()->attach([$alpha->id, $beta->id]);

    Project::query()->create(['team_id' => $alpha->id, 'name' => 'One']);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Two']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'Three']);

    $this->actingAs($user);

    $alphaStats = pageTableStats(collect(pageTableWidgets(inertiaProps(inertiaGet($this, '/ptt/alpha/pt-projects'))))->first());
    $betaStats = pageTableStats(collect(pageTableWidgets(inertiaProps(inertiaGet($this, '/ptt/beta/pt-projects'))))->first());
    $searched = pageTableStats(collect(pageTableWidgets(inertiaProps(inertiaGet($this, '/ptt/alpha/pt-projects?search=Thr'))))->first());

    expect($alphaStats)->toBe(['Projects' => 2])
        ->and($betaStats)->toBe(['Projects' => 1])
        ->and($searched)->toBe(['Projects' => 0]);
});

it('passes properties to widgets made with make()', function (): void {
    $draft = StatusCountWidget::make(['status' => 'draft']);
    $published = StatusCountWidget::make(['status' => 'published']);

    expect($draft->status)->toBe('draft')
        ->and($draft->getMakeProperties())->toBe(['status' => 'draft'])
        ->and($draft->getId())->not->toBe($published->getId())
        ->and($draft->getId())->toBe(StatusCountWidget::make(['status' => 'draft'])->getId())
        ->and(StatusCountWidget::make()->getId())->toBe(Widget::idFromClass(StatusCountWidget::class));
});

it('refuses unknown and non-public widget properties', function (): void {
    expect(fn () => StatusCountWidget::make(['missing' => 1]))->toThrow(InvalidArgumentException::class, 'has no property [missing]')
        ->and(fn () => GuardedWidget::make(['secret' => 'x']))->toThrow(InvalidArgumentException::class, 'must be public')
        ->and(fn () => StatusCountWidget::make(['page' => null]))->toThrow(InvalidArgumentException::class, 'must be public');
});

it('renders widgets made with properties on resource pages, dashboards and custom pages', function (): void {
    pageTablePanel();
    $this->actingAs(pageTableSeed());

    $list = array_values(array_filter(
        pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-posts'))),
        static fn (array $widget): bool => str_starts_with($widget['id'], Widget::idFromClass(StatusCountWidget::class).'--'),
    ));
    $dashboard = pageTableWidgets(inertiaProps(inertiaGet($this, '/pt'.PageTableDashboard::getRoutePath())));
    $report = pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-report')));

    expect(array_map(pageTableStats(...), $list))->toBe([['Status draft' => 2], ['Status published' => 2]])
        ->and(array_map(pageTableStats(...), array_values($dashboard)))->toBe([['Status draft' => 2], ['Status archived' => 1]])
        ->and(pageTableStats($report[StatusCountWidget::make(['status' => 'published'])->getId()]))->toBe(['Status published' => 2]);

    // A poll finds each configured instance again by its id.
    $id = StatusCountWidget::make(['status' => 'archived'])->getId();
    $polled = pageTablePartial($this, '/pt'.PageTableDashboard::getRoutePath(), Page::POLL_PROP, [Page::POLL_HEADER => "widget:{$id}"]);

    expect(pageTableStats($polled[Page::POLL_PROP]["widget:{$id}"]))->toBe(['Status archived' => 1]);
});

it('reads the table of the page named by getTablePage() from another page', function (): void {
    pageTablePanel();
    $this->actingAs(pageTableSeed());
    $id = Widget::idFromClass(PostTableStats::class);

    $report = pageTableWidgets(inertiaProps(inertiaGet($this, '/pt/pt-report?activeTab=published&filters[status][value]=published')));

    expect(pageTableStats($report[$id]))->toBe(['Matching' => 2, 'On page' => 2, 'Total' => 2, 'Rating' => 7]);
});
