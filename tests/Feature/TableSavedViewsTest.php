<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Grouping\Group;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\Views\SavedTableView;
use AlfaSix\Alfakit\Tables\Views\TableView;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpPostResource;
use AlfaSix\Alfakit\Tests\Fixtures\TableViews\TvProjectResource;
use AlfaSix\Alfakit\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/0001_01_01_000400_create_alfakit_table_views_table.php')->up();
    SavedTableView::flushAvailability();
    TpPanelProvider::registerPanel();
    TpPostResource::$table = null;
});

afterEach(function (): void {
    TpPostResource::$table = null;
    SavedTableView::flushAvailability();
});

function tvUser(string $email = 'views@example.com'): User
{
    return User::query()->create(['name' => 'Views', 'email' => $email, 'password' => 'x']);
}

function tvPosts(User $user): void
{
    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'author_id' => $user->id, 'status' => 'draft']);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta', 'author_id' => $user->id, 'status' => 'published']);
    Post::query()->create(['title' => 'Gamma', 'slug' => 'gamma', 'author_id' => $user->id, 'status' => 'published']);
}

function tvTable(TestCase $test, string $query = ''): array
{
    return inertiaProps(inertiaGet($test, '/tp/posts'.$query))['page']['content'][0];
}

function tvToken(TestCase $test, string $query = ''): string
{
    return inertiaProps(inertiaGet($test, '/tp/posts'.$query))['page']['token'];
}

/**
 * @param  array<string, mixed>  $data
 */
function tvPost(TestCase $test, string $token, array $data, string $query = ''): Illuminate\Testing\TestResponse
{
    return $test->postJson('/tp/_alfakit/table/views', ['token' => $token, 'component' => 'table', 'tableQuery' => $query, ...$data]);
}

function tvConfigure(): Closure
{
    return static fn (Table $table): Table => $table
        ->columns([
            TextColumn::make('title')->sortable(),
            TextColumn::make('status')->toggleable(),
            TextColumn::make('slug')->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])])
        ->groups([Group::make('status')])
        ->paginationPageOptions([5, 10, 25])
        ->savedViews();
}

class TvSharedViewsPolicy
{
    public static bool $allowed = false;

    public function manageSharedTableViews(User $user): bool
    {
        return self::$allowed;
    }
}

it('applies a code view from the URL and marks it modified when the URL changes it', function (): void {
    $user = tvUser();
    tvPosts($user);
    $this->actingAs($user);

    TpPostResource::$table = static fn (Table $table): Table => tvConfigure()($table)->views([
        TableView::make('published')
            ->label('Published')
            ->filters(['status' => ['value' => 'published']])
            ->sort('title', 'desc')
            ->columns(['slug', 'title'])
            ->perPage(5),
    ]);

    $table = tvTable($this, '?view=published');

    expect($table['views']['active'])->toBe('published')
        ->and($table['views'])->not->toHaveKey('modified')
        ->and($table['views']['items'][0])->toMatchArray(['key' => 'published', 'label' => 'Published', 'type' => 'preset'])
        ->and(array_column($table['records'], 'key'))->toBe([3, 2])
        ->and($table['sort'])->toBe(['column' => 'title', 'direction' => 'desc'])
        ->and($table['visibleColumns'])->toBe(['slug', 'title'])
        ->and($table['pagination']['perPage'])->toBe(5);

    // A change on top of the view stays in the URL and marks the view modified.
    $modified = tvTable($this, '?view=published&sort=title&direction=asc');

    expect($modified['views']['active'])->toBe('published')
        ->and($modified['views']['modified'])->toBeTrue()
        ->and(array_column($modified['records'], 'key'))->toBe([2, 3]);

    // Setting the same value as the view is not a modification.
    expect(tvTable($this, '?view=published&search=')['views'])->not->toHaveKey('modified');

    // `?view=` (empty) is explicitly no view.
    $none = tvTable($this, '?view=');

    expect($none['views'])->not->toHaveKey('active')
        ->and($none['records'])->toHaveCount(3);
});

it('applies the default code view only when the URL names none', function (): void {
    $user = tvUser();
    tvPosts($user);
    $this->actingAs($user);

    TpPostResource::$table = static fn (Table $table): Table => tvConfigure()($table)->views([
        TableView::make('drafts')->filters(['status' => ['value' => 'draft']])->default(),
        TableView::make('hidden')->hidden(),
    ]);

    $table = tvTable($this);

    expect($table['views']['active'])->toBe('drafts')
        ->and(array_column($table['views']['items'], 'key'))->toBe(['drafts'])
        ->and($table['views']['items'][0]['default'])->toBeTrue()
        ->and(array_column($table['records'], 'key'))->toBe([1]);

    // The request's own state wins over the default view's.
    expect(tvTable($this, '?filters[status][value]=published')['records'])->toHaveCount(2);

    // An unknown view falls back to the default.
    expect(tvTable($this, '?view=nope')['views']['active'])->toBe('drafts');
});

it('saves, updates, renames and deletes a personal view from the current table state', function (): void {
    $user = tvUser();
    tvPosts($user);
    $this->actingAs($user);
    TpPostResource::$table = tvConfigure();

    $query = '?filters[status][value]=published&sort=title&direction=desc&columns[0]=slug&perPage=5&group=status';
    $token = tvToken($this, $query);

    $created = tvPost($this, $token, ['operation' => 'create', 'name' => '  Published posts '], $query)->assertOk()->json();
    $slug = $created['view'];
    $stored = SavedTableView::query()->sole();

    expect($slug)->toStartWith('published-posts-')
        ->and($stored->name)->toBe('Published posts')
        ->and($stored->is_shared)->toBeFalse()
        ->and($stored->state)->toBe([
            'filters' => ['status' => ['value' => 'published']],
            'search' => null,
            'sort' => ['column' => 'title', 'direction' => 'desc'],
            'columns' => ['title', 'slug'],
            'group' => ['id' => 'status', 'direction' => null],
            'perPage' => 5,
        ])
        ->and($created['views']['items'][0])->toMatchArray(['key' => $slug, 'type' => 'personal', 'editable' => true]);

    // Opening the view restores the whole state.
    $table = tvTable($this, "?view={$slug}");

    expect($table['views']['active'])->toBe($slug)
        ->and(array_column($table['records'], 'key'))->toBe([3, 2])
        ->and($table['visibleColumns'])->toBe(['title', 'slug'])
        ->and($table['grouping']['group'])->toBe('status');

    // Update it from a changed state.
    tvPost($this, $token, ['operation' => 'update', 'target' => $slug], "?view={$slug}&sort=title&direction=asc")->assertOk();

    expect($stored->fresh()->state['sort'])->toBe(['column' => 'title', 'direction' => 'asc']);

    tvPost($this, $token, ['operation' => 'rename', 'target' => $slug, 'name' => 'Renamed'])->assertOk();
    tvPost($this, $token, ['operation' => 'rename', 'target' => $slug, 'name' => ''])->assertStatus(422);

    expect($stored->fresh()->name)->toBe('Renamed');

    tvPost($this, $token, ['operation' => 'delete', 'target' => $slug])->assertOk()->assertJson(['deleted' => $slug]);

    expect(SavedTableView::query()->count())->toBe(0);
});

it('keeps personal views private and refuses changes to views users do not own', function (): void {
    $owner = tvUser();
    tvPosts($owner);
    TpPostResource::$table = tvConfigure();

    $this->actingAs($owner);
    $slug = tvPost($this, tvToken($this), ['operation' => 'create', 'name' => 'Mine'])->json('view');

    $other = tvUser('other@example.com');
    $this->actingAs($other);
    $token = tvToken($this);

    expect(tvTable($this, "?view={$slug}")['views'])->not->toHaveKey('active');

    tvPost($this, $token, ['operation' => 'delete', 'target' => $slug])->assertNotFound();
    tvPost($this, $token, ['operation' => 'update', 'target' => $slug])->assertNotFound();
    tvPost($this, $token, ['operation' => 'default', 'target' => $slug])->assertNotFound();

    // Code views are read-only.
    TpPostResource::$table = static fn (Table $table): Table => tvConfigure()($table)->views([TableView::make('preset')]);

    tvPost($this, tvToken($this), ['operation' => 'delete', 'target' => 'preset'])->assertForbidden();

    expect(SavedTableView::query()->count())->toBe(1);
});

it('publishes shared views only with the manageSharedTableViews permission', function (): void {
    $author = tvUser();
    tvPosts($author);
    Gate::policy(Post::class, TvSharedViewsPolicy::class);
    TvSharedViewsPolicy::$allowed = false;
    TpPostResource::$table = tvConfigure();

    $this->actingAs($author);
    $token = tvToken($this);

    expect(tvTable($this)['views'])->not->toHaveKey('canShare');

    tvPost($this, $token, ['operation' => 'create', 'name' => 'Team', 'shared' => true])->assertForbidden();

    TvSharedViewsPolicy::$allowed = true;

    expect(tvTable($this)['views']['canShare'])->toBeTrue();

    $slug = tvPost($this, $token, ['operation' => 'create', 'name' => 'Team', 'shared' => true], '?filters[status][value]=draft')->assertOk()->json('view');

    // Everyone on the panel and table sees it; only managers may change it.
    TvSharedViewsPolicy::$allowed = false;
    $this->actingAs(tvUser('reader@example.com'));
    $readerToken = tvToken($this);
    $table = tvTable($this, "?view={$slug}");

    expect($table['views']['active'])->toBe($slug)
        ->and($table['views']['items'][0])->toMatchArray(['type' => 'shared'])
        ->and($table['views']['items'][0])->not->toHaveKey('editable')
        ->and($table['records'])->toHaveCount(1);

    tvPost($this, $readerToken, ['operation' => 'rename', 'target' => $slug, 'name' => 'Hijacked'])->assertForbidden();
    tvPost($this, $readerToken, ['operation' => 'delete', 'target' => $slug])->assertForbidden();

    // A reader may still pick it as their own default.
    tvPost($this, $readerToken, ['operation' => 'default', 'target' => $slug])->assertOk();

    expect(tvTable($this)['views'])->toMatchArray(['active' => $slug, 'userDefault' => $slug]);

    TvSharedViewsPolicy::$allowed = true;
    tvPost($this, $readerToken, ['operation' => 'share', 'target' => $slug, 'shared' => false])->assertOk();

    expect(SavedTableView::query()->sole()->is_shared)->toBeFalse();
});

it('stores a user default view, and an explicit "no view" default over a code default', function (): void {
    $user = tvUser();
    tvPosts($user);
    $this->actingAs($user);
    TpPostResource::$table = static fn (Table $table): Table => tvConfigure()($table)->views([
        TableView::make('drafts')->filters(['status' => ['value' => 'draft']])->default(),
        TableView::make('published')->filters(['status' => ['value' => 'published']]),
    ]);

    $token = tvToken($this);

    tvPost($this, $token, ['operation' => 'default', 'target' => 'published'])->assertOk();

    expect(tvTable($this)['views']['active'])->toBe('published');

    tvPost($this, $token, ['operation' => 'default', 'none' => true])->assertOk();

    expect(tvTable($this)['views'])->not->toHaveKey('active')
        ->and(tvTable($this)['records'])->toHaveCount(3);

    tvPost($this, $token, ['operation' => 'default', 'target' => null])->assertOk();

    expect(tvTable($this)['views']['active'])->toBe('drafts');
});

it('sanitises stored state against the table on read', function (): void {
    $user = tvUser();
    tvPosts($user);
    $this->actingAs($user);
    TpPostResource::$table = tvConfigure();

    $token = tvToken($this);
    $slug = tvPost($this, $token, ['operation' => 'create', 'name' => 'Raw'])->json('view');

    SavedTableView::query()->sole()->update(['state' => [
        'filters' => ['status' => ['value' => 'draft', 'bogus' => 'x'], 'gone' => ['value' => 1]],
        'sort' => ['column' => 'secret', 'direction' => 'asc'],
        'columns' => ['slug', 'nope', 'title'],
        'group' => ['id' => 'unknown'],
        'perPage' => 9999,
        'unknown' => 'dropped',
    ]]);

    $table = Table::make();
    tvConfigure()($table);

    expect($table->sanitizeTableViewState(SavedTableView::query()->sole()->state))->toBe([
        'filters' => ['status' => ['value' => 'draft']],
        'sort' => null,
        'columns' => ['slug', 'title'],
        'group' => null,
        'perPage' => null,
    ]);

    $page = tvTable($this, "?view={$slug}");

    expect($page['views']['active'])->toBe($slug)
        ->and($page['records'])->toHaveCount(1)
        ->and($page['visibleColumns'])->toBe(['slug', 'title']);
});

it('keeps views out of tables that did not opt in, and follows the panel default', function (): void {
    $user = tvUser();
    $this->actingAs($user);
    TpPostResource::$table = static fn (Table $table): Table => $table->columns([TextColumn::make('title')]);

    expect(tvTable($this))->not->toHaveKey('views');

    tvPost($this, tvToken($this), ['operation' => 'create', 'name' => 'x'])->assertNotFound();

    alfakit()->getPanel('tp')->tableSavedViews();

    try {
        expect(tvTable($this)['views'])->toMatchArray(['layout' => 'tabs', 'canSave' => true]);

        TpPostResource::$table = static fn (Table $table): Table => $table->columns([TextColumn::make('title')])->savedViews(false);

        expect(tvTable($this))->not->toHaveKey('views');
    } finally {
        alfakit()->getPanel('tp')->tableSavedViews(false);
    }
});

it('binds view requests to the token owner and refuses guests', function (): void {
    $user = tvUser();
    TpPostResource::$table = tvConfigure();
    $this->actingAs($user);
    $token = tvToken($this);

    $this->actingAs(tvUser('thief@example.com'));
    tvPost($this, $token, ['operation' => 'create', 'name' => 'x'])->assertForbidden();

    expect(SavedTableView::query()->count())->toBe(0);
});

it('caps the number of views a user keeps on a table', function (): void {
    $user = tvUser();
    $this->actingAs($user);
    TpPostResource::$table = tvConfigure();
    $token = tvToken($this);

    foreach (range(1, Table::MAX_SAVED_VIEWS) as $index) {
        SavedTableView::query()->create([
            'panel' => 'tp', 'table_key' => tvKey(), 'tenant_key' => '',
            ...SavedTableView::owner($user), 'slug' => "v{$index}", 'name' => "V{$index}", 'state' => [],
        ]);
    }

    tvPost($this, $token, ['operation' => 'create', 'name' => 'One more'])->assertStatus(422);
});

function tvKey(): string
{
    return sha1(implode('|', [AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpListPosts::class, '', Post::class]));
}

it('scopes views to the tenant', function (): void {
    Panels::register('tvteams', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->resources([TvProjectResource::class]));

    $user = TenantUser::query()->create(['name' => 'Tess', 'email' => 'tess@example.com', 'password' => 'x']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $user->teams()->attach([$alpha->id, $beta->id]);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'A1']);
    Project::query()->create(['team_id' => $beta->id, 'name' => 'B1']);

    $this->actingAs($user);
    $props = inertiaProps(inertiaGet($this, '/tvteams/alpha/projects'));

    $slug = $this->postJson($props['alfakit']['endpoints']['tableViews'], [
        'token' => $props['page']['token'], 'component' => 'table', 'operation' => 'create', 'name' => 'Team view', 'shared' => true,
    ])->assertOk()->json('view');

    expect(SavedTableView::query()->sole()->tenant_key)->toBe((string) $alpha->id)
        ->and(inertiaProps(inertiaGet($this, "/tvteams/alpha/projects?view={$slug}"))['page']['content'][0]['views']['active'])->toBe($slug);

    $beta = inertiaProps(inertiaGet($this, "/tvteams/beta/projects?view={$slug}"))['page']['content'][0]['views'];

    expect($beta)->not->toHaveKey('active')
        ->and($beta['items'])->toBe([]);
});

it('carries the active view to internal requests through the page query', function (): void {
    expect(preg_match('/^(?:[A-Za-z0-9_-]+_)?(?:sort|direction|search|filters|columnSearch|columns|perPage|group|groupDirection|activeTab|reordering|view)$/', 'orders_view'))->toBe(1);

    $request = Illuminate\Http\Request::create('/x', 'POST', ['tableQuery' => '?view=drafts&evil=1']);
    AlfaSix\Alfakit\Tables\TableQueryString::apply($request);

    expect($request->query('view'))->toBe('drafts')
        ->and($request->query->has('evil'))->toBeFalse();
});
