<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * Inertia props: once props (translations, navigation, config), the per-request navigation
 * state, deferred navigation badges and deferred table totals.
 */

class InertiaPropsBadgePage extends Page
{
    protected static ?string $slug = 'inbox';

    protected static ?string $navigationLabel = 'Inbox';

    public static int $evaluated = 0;

    public static function getNavigationBadge(): string|int|null
    {
        self::$evaluated++;

        return 7;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }
}

class InertiaPropsPlainPage extends Page
{
    protected static ?string $slug = 'plain';

    protected static ?string $navigationLabel = 'Plain';
}

/**
 * @param  Closure(Panel): Panel  $configure
 */
function inertiaPropsPanel(?Closure $configure = null): void
{
    config()->set('auth.providers.users.model', MfaUser::class);
    app('auth')->forgetGuards();
    Gate::before(static fn (): bool => true);

    Panels::register('ip', fn (Panel $panel): Panel => ($configure ?? static fn (Panel $panel): Panel => $panel)($panel->login()->pages([Dashboard::class, InertiaPropsBadgePage::class, InertiaPropsPlainPage::class])));
}

/**
 * @param  array<string, string>  $headers
 */
function ipGet(mixed $test, string $url, array $headers = []): Illuminate\Testing\TestResponse
{
    return $test->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '', ...$headers]);
}

beforeEach(function (): void {
    InertiaPropsBadgePage::$evaluated = 0;
});

it('sends translations, navigation and panel config as once props keyed by their content', function (): void {
    inertiaPropsPanel();
    $this->actingAs(MfaUser::query()->create(['name' => 'Once', 'email' => 'once@example.com', 'password' => 'password']));

    $first = ipGet($this, '/ip/inbox')->assertOk()->json();
    $once = $first['onceProps'];

    expect(array_column($once, 'prop'))->toContain('alfakit.translations', 'alfakit.navigation', 'alfakit.endpoints', 'alfakit.localization', 'alfakit.theme')
        ->and(array_keys($once))->each->toMatch('/^alfakit\.[a-zA-Z]+:[0-9a-f]{16}$/')
        ->and($first['props']['alfakit'])->toHaveKeys(['translations', 'navigation', 'navigationState', 'csrf', 'user']);

    // A later visit that already has them: only the per-request parts travel.
    $second = ipGet($this, '/ip/plain', ['X-Inertia-Except-Once-Props' => implode(',', array_keys($once))])->assertOk()->json();

    expect($second['props']['alfakit'])->not->toHaveKeys(['translations', 'navigation', 'endpoints', 'localization', 'theme'])
        ->and($second['props']['alfakit'])->toHaveKeys(['navigationState', 'csrf', 'user'])
        ->and(array_keys($second['onceProps']))->toEqual(array_keys($once));

    // Other strings (another language, an updated pack): a new key, so the client's copy no longer counts.
    app('translator')->addLines(['ui.inertia_props_test' => 'Changed'], 'en', 'alfakit');
    $german = ipGet($this, '/ip/plain', ['X-Inertia-Except-Once-Props' => implode(',', array_keys($once))])->assertOk()->json();

    $translationsKey = static fn (array $props): string|false => array_search('alfakit.translations', array_map(static fn (array $prop): string => $prop['prop'], $props), true);

    expect($german['props']['alfakit'])->toHaveKey('translations')
        ->and($translationsKey($german['onceProps']))->not->toBe($translationsKey($once));
});

it('changes the navigation key when the user may see other items', function (): void {
    $hidden = false;
    inertiaPropsPanel(function (Panel $panel) use (&$hidden): Panel {
        return $panel->navigationItems([AlfaSix\Alfakit\Navigation\NavigationItem::make('Secret')->url('/secret')->visible(function () use (&$hidden): bool {
            return ! $hidden;
        })]);
    });
    $this->actingAs(MfaUser::query()->create(['name' => 'Perm', 'email' => 'perm@example.com', 'password' => 'password']));

    $key = fn (): string => (string) collect(ipGet($this, '/ip/inbox')->json('onceProps'))->search(fn (array $prop): bool => $prop['prop'] === 'alfakit.navigation');
    $before = $key();
    $hidden = true;

    expect($key())->not->toBe($before);
});

it('keeps the active items and badges out of the navigation structure', function (): void {
    inertiaPropsPanel();
    $this->actingAs(MfaUser::query()->create(['name' => 'State', 'email' => 'state@example.com', 'password' => 'password']));

    $alfakit = ipGet($this, '/ip/inbox')->json('props.alfakit');
    $items = collect($alfakit['navigation'])->flatMap(fn (array $group): array => $group['items']);

    expect($items->pluck('active')->filter()->all())->toBe([])
        ->and($items->pluck('badge')->filter()->all())->toBe([]);

    $resolved = collect(navigationWithState($alfakit))->flatMap(fn (array $group): array => $group['items'])->keyBy('label');

    expect($resolved['Inbox'])->toMatchArray(['active' => true, 'badge' => 7, 'badgeColor' => 'danger'])
        ->and($resolved['Plain'])->not->toHaveKey('active');
});

it('can send the panel props without once props', function (): void {
    inertiaPropsPanel(fn (Panel $panel): Panel => $panel->onceProps(false));
    $this->actingAs(MfaUser::query()->create(['name' => 'NoOnce', 'email' => 'noonce@example.com', 'password' => 'password']));

    expect(ipGet($this, '/ip/inbox')->json())->not->toHaveKey('onceProps');
});

it('defers navigation badges to a deferred prop that skips the rest of the page', function (): void {
    inertiaPropsPanel(fn (Panel $panel): Panel => $panel->deferNavigationBadges());
    $this->actingAs(MfaUser::query()->create(['name' => 'Defer', 'email' => 'defer@example.com', 'password' => 'password']));

    $page = ipGet($this, '/ip/inbox')->assertOk()->json();
    $items = collect($page['props']['alfakit']['navigation'])->flatMap(fn (array $group): array => $group['items'])->keyBy('label');

    expect(InertiaPropsBadgePage::$evaluated)->toBe(0)
        ->and($items['Inbox'])->toMatchArray(['deferredBadge' => true])->not->toHaveKey('badge')
        ->and($items['Plain'])->not->toHaveKey('deferredBadge')
        ->and($page['deferredProps'])->toHaveKey('alfakitNavigationBadges')
        ->and($page['props'])->not->toHaveKey('alfakitNavigationBadges');

    $id = collect($page['props']['alfakit']['navigation'])->flatMap(fn (array $group, int $g): array => collect($group['items'])->map(fn (array $item, int $i): array => ['label' => $item['label'], 'id' => "{$g}.{$i}"])->all())->firstWhere('label', 'Inbox')['id'];

    $badges = ipGet($this, '/ip/inbox', [
        'X-Inertia-Partial-Component' => $page['component'],
        'X-Inertia-Partial-Data' => 'alfakitNavigationBadges',
    ])->assertOk()->json('props');

    expect($badges)->toHaveKey('alfakitNavigationBadges')
        ->and($badges)->not->toHaveKeys(['alfakit', 'page'])
        ->and($badges['alfakitNavigationBadges'][$id])->toBe(['badge' => 7, 'badgeColor' => 'danger']);
});

function ipPostsUser(): User
{
    return User::query()->create(['name' => 'Totals', 'email' => 'totals'.uniqid().'@example.com', 'password' => 'password']);
}

function ipSeedPosts(int $count): void
{
    foreach (range(1, $count) as $i) {
        Post::query()->create(['title' => "Totals {$i}", 'slug' => "totals-{$i}-".uniqid()]);
    }
}

/**
 * @return list<string>
 */
function ipCountQueries(Closure $callback): array
{
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        if (str_contains(strtolower($query->sql), 'count(') && str_contains($query->sql, '"posts"')) {
            $queries[] = $query->sql;
        }
    });

    $callback();

    return $queries;
}

it('renders the rows of deferTotals() tables without the count query', function (): void {
    Table::configureUsing(fn (Table $table) => $table->deferTotals()->paginated([2])->defaultPaginationPageOption(2), function (): void {
        $user = ipPostsUser();
        ipSeedPosts(5);
        $props = [];

        $counts = ipCountQueries(function () use ($user, &$props): void {
            $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts?page=2'));
        });
        $table = $props['page']['content'][0];

        expect($counts)->toBe([])
            ->and($table['records'])->toHaveCount(2)
            ->and($table['pagination'])->toMatchArray(['mode' => 'simple', 'currentPage' => 2, 'hasMorePages' => true, 'pendingTotal' => true, 'from' => 3, 'to' => 4])
            ->and($table['deferredTotals']['key'])->toBeString()
            ->and($props['alfakit']['endpoints']['tableTotals'])->toBe('http://localhost/admin/_alfakit/table/totals');

        $totals = $this->postJson('/admin/_alfakit/table/totals', [
            'token' => $props['page']['token'],
            'component' => 'table',
            'tableQuery' => 'page=2',
        ])->assertOk()->json();

        expect($totals)->toBe(['total' => 5, 'lastPage' => 3]);

        // Paging keeps the key (no page summary), searching changes it.
        $key = fn (string $query): string => inertiaProps(inertiaGet($this->actingAs($user), "/admin/posts?{$query}"))['page']['content'][0]['deferredTotals']['key'];

        expect($key('page=1'))->toBe($table['deferredTotals']['key'])
            ->and($key('search=Totals%201'))->not->toBe($table['deferredTotals']['key']);
    });
});

it('loads the totals with the filters of the page and answers partial reloads without them', function (): void {
    Table::configureUsing(fn (Table $table) => $table->deferTotals()->paginated([2])->defaultPaginationPageOption(2), function (): void {
        $user = ipPostsUser();
        ipSeedPosts(3);
        $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['token'];

        expect($this->postJson('/admin/_alfakit/table/totals', ['token' => $token, 'component' => 'table', 'tableQuery' => 'search=Totals%202'])->json())
            ->toBe(['total' => 1, 'lastPage' => 1]);

        // Sorting / paging is a partial reload of `page`: no count, and the shared panel
        // props are not rebuilt.
        $partial = [];
        $counts = ipCountQueries(function () use ($user, &$partial): void {
            $partial = $this->actingAs($user)->get('/admin/posts?page=2&sort=title', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => '',
                'X-Inertia-Partial-Component' => 'Alfakit/Page',
                'X-Inertia-Partial-Data' => 'page',
            ])->assertOk()->json('props');
        });

        expect($counts)->toBe([])
            ->and($partial)->toHaveKey('page')
            ->and($partial)->not->toHaveKey('alfakit')
            ->and($partial['page']['content'][0]['pagination']['pendingTotal'])->toBeTrue();
    });
});

it('counts a page past the end right away to show the last page', function (): void {
    Table::configureUsing(fn (Table $table) => $table->deferTotals()->paginated([2])->defaultPaginationPageOption(2), function (): void {
        $user = ipPostsUser();
        ipSeedPosts(3);

        $table = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts?page=9'))['page']['content'][0];

        expect($table['pagination'])->toMatchArray(['currentPage' => 2, 'lastPage' => 2, 'total' => 3])
            ->and($table['records'])->toHaveCount(1);
    });
});

it('keeps totals inline without deferTotals() or for polled tables', function (): void {
    $user = ipPostsUser();
    ipSeedPosts(3);

    expect(inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['content'][0])->not->toHaveKey('deferredTotals');

    Table::configureUsing(fn (Table $table) => $table->deferTotals()->poll('10s'), function () use ($user): void {
        $table = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['content'][0];

        expect($table)->not->toHaveKey('deferredTotals')
            ->and($table['pagination'])->toHaveKey('total');
    });
});

it('binds totals requests to the token owner', function (): void {
    Table::configureUsing(fn (Table $table) => $table->deferTotals(), function (): void {
        $token = inertiaProps(inertiaGet($this->actingAs(ipPostsUser()), '/admin/posts'))['page']['token'];

        $this->actingAs(ipPostsUser())
            ->postJson('/admin/_alfakit/table/totals', ['token' => $token, 'component' => 'table'])
            ->assertForbidden();
    });
});
