<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\TextInputColumn;
use AlfaSix\Alfakit\Tables\Columns\ToggleColumn;
use AlfaSix\Alfakit\Tables\Table;
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
    TpPanelProvider::registerPanel();
    TpPostResource::$table = null;
    SsPostPolicy::$denied = [];
});

afterEach(function (): void {
    TpPostResource::$table = null;
});

class SsPostPolicy
{
    /** @var array<int> Posts the user may not update. */
    public static array $denied = [];

    public function update(User $user, Post $post): bool
    {
        return ! in_array($post->id, self::$denied, true);
    }
}

function ssSetUp(TestCase $test, ?Closure $configure = null): string
{
    $user = User::query()->create(['name' => 'Sheet', 'email' => 'sheet@example.com', 'password' => 'x']);

    foreach (['Alpha', 'Beta', 'Gamma'] as $index => $title) {
        Post::query()->create(['title' => $title, 'slug' => strtolower($title), 'author_id' => $user->id, 'status' => 'draft', 'rating' => $index + 1]);
    }

    TpPostResource::$table = $configure ?? static fn (Table $table): Table => $table
        ->columns([
            TextInputColumn::make('title')->rules(['required', 'max:20']),
            SelectColumn::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
            ToggleColumn::make('is_published'),
            TextColumn::make('rating')->editable()->rules(['integer', 'between:1,5']),
            TextColumn::make('slug'),
        ])
        ->spreadsheet();

    $test->actingAs($user);

    return inertiaProps(inertiaGet($test, '/tp/posts'))['page']['token'];
}

/**
 * @param  array<int, array<string, mixed>>  $changes
 */
function ssSave(TestCase $test, string $token, array $changes, array $extra = []): Illuminate\Testing\TestResponse
{
    return $test->postJson('/tp/_alfakit/table/cells', ['token' => $token, 'component' => 'table', 'changes' => $changes, ...$extra]);
}

it('serialises the editable columns and the raw value of editable text columns', function (): void {
    ssSetUp($this);

    $props = inertiaProps(inertiaGet($this, '/tp/posts'));
    $table = $props['page']['content'][0];

    expect($table['spreadsheet'])->toBe(['columns' => ['title', 'status', 'is_published', 'rating'], 'maxChanges' => 1000])
        ->and($table['records'][0]['cells']['rating']['editState'])->toBe(1)
        ->and($table['records'][0]['cells']['slug'])->not->toHaveKey('editState')
        ->and($props['alfakit']['endpoints']['tableCells'])->toBe('http://localhost/tp/_alfakit/table/cells');
});

it('saves a batch in one request and reports the previous values', function (): void {
    $token = ssSetUp($this);

    $response = ssSave($this, $token, [
        ['record' => 1, 'column' => 'title', 'state' => 'Alpha 2'],
        ['record' => 2, 'column' => 'status', 'state' => 'published'],
        ['record' => 3, 'column' => 'is_published', 'state' => true],
        ['record' => 3, 'column' => 'rating', 'state' => '5'],
    ])->assertOk()->json();

    expect($response['errors'])->toBe([])
        ->and($response['saved'])->toHaveCount(4)
        ->and($response['saved'][0])->toBe(['record' => 1, 'column' => 'title', 'state' => 'Alpha 2', 'previous' => 'Alpha'])
        ->and(Post::query()->find(1)->title)->toBe('Alpha 2')
        ->and(Post::query()->find(2)->status)->toBe('published')
        ->and(Post::query()->find(3)->is_published)->toBeTrue()
        ->and(Post::query()->find(3)->rating)->toBe(5);
});

it('validates every cell with its column rules and saves nothing when one fails', function (): void {
    $token = ssSetUp($this);

    $response = ssSave($this, $token, [
        ['record' => 1, 'column' => 'title', 'state' => 'Fine'],
        ['record' => 2, 'column' => 'title', 'state' => ''],
        ['record' => 3, 'column' => 'status', 'state' => 'bogus'],
        ['record' => 3, 'column' => 'rating', 'state' => '9'],
    ])->assertStatus(422)->json();

    expect($response['saved'])->toBe([])
        ->and(array_map(fn (array $error): array => [$error['record'], $error['column']], $response['errors']))->toBe([[2, 'title'], [3, 'status'], [3, 'rating']])
        ->and($response['errors'][0]['messages'][0])->toContain('required')
        ->and(Post::query()->find(1)->title)->toBe('Alpha');
});

it('only validates on a dry run', function (): void {
    $token = ssSetUp($this);

    $response = ssSave($this, $token, [
        ['record' => 1, 'column' => 'title', 'state' => 'Valid'],
        ['record' => 2, 'column' => 'rating', 'state' => 'x'],
    ], ['dryRun' => true])->assertOk()->json();

    expect($response['saved'])->toBe([])
        ->and($response['errors'])->toHaveCount(1)
        ->and(Post::query()->find(1)->title)->toBe('Alpha');
});

it('saves the valid cells of a non-atomic batch and reports the rest', function (): void {
    $token = ssSetUp($this, static fn (Table $table): Table => $table
        ->columns([TextInputColumn::make('title')->rules(['required'])])
        ->spreadsheet(atomic: false));

    $response = ssSave($this, $token, [
        ['record' => 1, 'column' => 'title', 'state' => 'Saved'],
        ['record' => 2, 'column' => 'title', 'state' => ''],
    ])->assertOk()->json();

    expect($response['saved'])->toHaveCount(1)
        ->and($response['errors'])->toHaveCount(1)
        ->and(Post::query()->find(1)->title)->toBe('Saved')
        ->and(Post::query()->find(2)->title)->toBe('Beta');
});

it('checks the update policy on every record', function (): void {
    Gate::policy(Post::class, SsPostPolicy::class);
    SsPostPolicy::$denied = [2];
    $token = ssSetUp($this);

    $response = ssSave($this, $token, [
        ['record' => 1, 'column' => 'title', 'state' => 'Mine'],
        ['record' => 2, 'column' => 'title', 'state' => 'Not mine'],
    ])->assertStatus(422)->json();

    expect($response['errors'])->toHaveCount(1)
        ->and($response['errors'][0]['record'])->toBe(2)
        ->and(Post::query()->pluck('title', 'id')->all())->toBe([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma']);
});

it('refuses read-only columns, unknown and trashed records and disabled cells', function (): void {
    $token = ssSetUp($this, static fn (Table $table): Table => $table
        ->columns([
            TextInputColumn::make('title')->disabled(fn (Post $record): bool => $record->id === 3),
            TextColumn::make('slug'),
            TextInputColumn::make('content')->editable(false),
        ])
        ->spreadsheet(atomic: false));

    Post::query()->find(2)->delete();

    $response = ssSave($this, $token, [
        ['record' => 1, 'column' => 'slug', 'state' => 'hacked'],
        ['record' => 1, 'column' => 'content', 'state' => 'hacked'],
        ['record' => 2, 'column' => 'title', 'state' => 'Trashed'],
        ['record' => 99, 'column' => 'title', 'state' => 'Missing'],
        ['record' => 3, 'column' => 'title', 'state' => 'Disabled'],
    ])->assertStatus(422)->json();

    expect($response['errors'])->toHaveCount(5)
        ->and(Post::withTrashed()->pluck('title', 'id')->all())->toBe([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma'])
        ->and(Post::query()->find(1)->slug)->toBe('alpha');

    // The inline cell endpoint does not edit text columns either.
    $this->postJson('/tp/_alfakit/table/cells', ['token' => $token, 'component' => 'table', 'changes' => 'x'])->assertStatus(422);
    $this->postJson('/tp/_alfakit/table/cell', ['token' => $token, 'component' => 'table', 'record' => 1, 'column' => 'slug', 'state' => 'x'])->assertNotFound();
});

it('reports a conflict when a cell no longer holds the expected value (undo after save)', function (): void {
    $token = ssSetUp($this);

    ssSave($this, $token, [['record' => 1, 'column' => 'title', 'state' => 'Changed']])->assertOk();

    // Someone else edits it meanwhile: the undo of the first save is refused.
    Post::query()->find(1)->update(['title' => 'Theirs']);

    $response = ssSave($this, $token, [['record' => 1, 'column' => 'title', 'state' => 'Alpha', 'original' => 'Changed']])->assertStatus(422)->json();

    expect($response['errors'][0]['messages'][0])->toBe(__('alfakit::tables.spreadsheet.conflict'))
        ->and(Post::query()->find(1)->title)->toBe('Theirs');

    ssSave($this, $token, [['record' => 1, 'column' => 'title', 'state' => 'Alpha', 'original' => 'Theirs']])->assertOk();
    ssSave($this, $token, [['record' => 2, 'column' => 'rating', 'state' => 4, 'original' => '2']])->assertOk();

    expect(Post::query()->find(1)->title)->toBe('Alpha');
});

it('refuses tables without spreadsheet mode and malformed batches', function (): void {
    $token = ssSetUp($this, static fn (Table $table): Table => $table->columns([TextInputColumn::make('title')]));

    expect(inertiaProps(inertiaGet($this, '/tp/posts'))['page']['content'][0])->not->toHaveKey('spreadsheet');

    ssSave($this, $token, [['record' => 1, 'column' => 'title', 'state' => 'x']])->assertNotFound();

    TpPostResource::$table = static fn (Table $table): Table => $table->columns([TextInputColumn::make('title')])->spreadsheet();

    ssSave($this, $token, [['record' => 1, 'column' => 'title', 'state' => ['nested']]])->assertStatus(422);
    ssSave($this, $token, [['record' => ['x'], 'column' => 'title', 'state' => 'x']])->assertStatus(422);
    ssSave($this, $token, array_fill(0, Table::MAX_SPREADSHEET_CHANGES + 1, ['record' => 1, 'column' => 'title', 'state' => 'x']))->assertStatus(422);
});

it('binds batches to the token owner', function (): void {
    $token = ssSetUp($this);

    $this->actingAs(User::query()->create(['name' => 'Other', 'email' => 'other-sheet@example.com', 'password' => 'x']));

    ssSave($this, $token, [['record' => 1, 'column' => 'title', 'state' => 'x']])->assertForbidden();

    expect(Post::query()->find(1)->title)->toBe('Alpha');
});

it('only edits records of the current tenant', function (): void {
    Panels::register('ssteams', fn (Panel $panel): Panel => $panel
        ->tenant(Team::class, slugAttribute: 'slug')
        ->resources([TvProjectResource::class]));

    $user = TenantUser::query()->create(['name' => 'Tess', 'email' => 'tess-sheet@example.com', 'password' => 'x']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $beta = Team::query()->create(['name' => 'Beta', 'slug' => 'beta']);
    $user->teams()->attach([$alpha->id, $beta->id]);
    $own = Project::query()->create(['team_id' => $alpha->id, 'name' => 'A1']);
    $foreign = Project::query()->create(['team_id' => $beta->id, 'name' => 'B1']);

    $this->actingAs($user);
    $props = inertiaProps(inertiaGet($this, '/ssteams/alpha/projects'));

    $response = $this->postJson($props['alfakit']['endpoints']['tableCells'], [
        'token' => $props['page']['token'],
        'component' => 'table',
        'changes' => [
            ['record' => $own->id, 'column' => 'name', 'state' => 'A1 renamed'],
            ['record' => $foreign->id, 'column' => 'name', 'state' => 'Stolen'],
        ],
    ])->assertStatus(422)->json();

    expect($response['errors'][0]['record'])->toBe($foreign->id)
        ->and($foreign->fresh()->name)->toBe('B1')
        ->and($own->fresh()->name)->toBe('A1');
});
