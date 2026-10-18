<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Grouping\Group;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpPanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\TableParity\TpPostResource;
use AlfaSix\Alfakit\Tests\TestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    TpPanelProvider::registerPanel();
    TpPostResource::$table = null;
});

afterEach(function (): void {
    TpPostResource::$table = null;
});

function tpUser(): User
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'author_id' => $user->id, 'status' => 'draft']);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta', 'author_id' => $user->id, 'status' => 'draft']);
    Post::query()->create(['title' => 'Gamma', 'slug' => 'gamma', 'author_id' => $user->id, 'status' => 'published']);

    return $user;
}

function tpPage(TestCase $test, string $query = ''): array
{
    return inertiaProps(inertiaGet($test, '/tp/posts'.$query))['page'];
}

it('persists records per page, toggled columns and grouping in the session', function (): void {
    $this->actingAs(tpUser());

    TpPostResource::$table = static fn (Table $table): Table => $table
        ->persistInSession()
        ->paginationPageOptions([1, 2, 10])
        ->groups([Group::make('status')])
        ->columns([TextColumn::make('title'), TextColumn::make('slug')->toggleable()]);

    inertiaGet($this, '/tp/posts?perPage=2&columns=&group=status&groupDirection=desc')->assertOk();

    $table = tpPage($this)['content'][0];

    expect($table['pagination']['perPage'])->toBe(2)
        ->and($table['visibleColumns'])->toBe(['title'])
        ->and($table['grouping']['group'])->toBe('status')
        ->and($table['grouping']['direction'])->toBe('desc')
        ->and($table['persistedState'])->toBe(['filters', 'search', 'columnSearch', 'sort', 'perPage', 'columns', 'group']);

    // Changing only the direction keeps the stored group.
    inertiaGet($this, '/tp/posts?groupDirection=asc')->assertOk();
    $table = tpPage($this)['content'][0];

    expect($table['grouping']['group'])->toBe('status')
        ->and($table['grouping']['direction'])->toBe('asc');

    // Cleared values sent as empty keys return to the defaults.
    inertiaGet($this, '/tp/posts?perPage=&columns[0]=slug&group=')->assertOk();
    $table = tpPage($this)['content'][0];

    expect($table['pagination']['perPage'])->toBe(10)
        ->and($table['visibleColumns'])->toBe(['title', 'slug'])
        ->and($table)->not->toHaveKey('grouping');
});

it('refuses bulk selections above maxSelectableRecords', function (): void {
    $this->actingAs(tpUser());

    TpPostResource::$table = static fn (Table $table): Table => $table
        ->maxSelectableRecords(2)
        ->bulkActions([BulkAction::make('remove')->action(fn (Collection $records) => $records->each->delete())]);

    $token = tpPage($this)['token'];
    $run = fn (array $data) => inertiaPost($this, '/tp/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'remove', 'bulk' => true, ...$data]);

    $run(['records' => [1, 2, 3]])->assertStatus(422);
    $run(['allRecords' => true, 'records' => []])->assertStatus(422);

    expect(Post::query()->count())->toBe(3);

    $run(['records' => [1, 2]])->assertRedirect();

    expect(Post::query()->count())->toBe(1);
});

it('refuses bulk selections across groups with selectGroupsOnly', function (): void {
    $this->actingAs(tpUser());

    TpPostResource::$table = static fn (Table $table): Table => $table
        ->groups([Group::make('status')])
        ->defaultGroup('status')
        ->selectGroupsOnly()
        ->bulkActions([BulkAction::make('remove')->action(fn (Collection $records) => $records->each->delete())]);

    $token = tpPage($this)['token'];
    $run = fn (array $records) => inertiaPost($this, '/tp/_alfakit/actions', ['token' => $token, 'component' => 'table', 'action' => 'remove', 'bulk' => true, 'records' => $records]);

    $run([1, 3])->assertStatus(422);
    $run([1, 2])->assertRedirect();

    expect(Post::query()->pluck('id')->all())->toBe([3]);
});

it('gives a record action the selection through the action endpoint, within the table query', function (): void {
    $this->actingAs(tpUser());
    $seen = new ArrayObject;

    TpPostResource::$table = static fn (Table $table): Table => $table
        // Records outside the table's query are never reachable through the selection.
        ->modifyQueryUsing(fn (Builder $query) => $query->where('slug', '!=', 'gamma'))
        ->selectable()
        ->recordActions([
            Action::make('copy')->accessSelectedRecords()->action(function (Post $record, Collection $selectedRecords) use ($seen): void {
                $seen['value'] = [$record->id, $selectedRecords->modelKeys()];
            }),
        ]);

    $token = tpPage($this)['token'];

    inertiaPost($this, '/tp/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'copy',
        'record' => 1,
        'arguments' => [Action::SELECTED_RECORDS_ARGUMENT => [2, 3, 999]],
    ])->assertRedirect();

    expect($seen['value'] ?? null)->toBe([1, [2]]);
});

it('searches select column options through the schema options endpoint', function (): void {
    $this->actingAs(tpUser());
    $bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

    TpPostResource::$table = static fn (Table $table): Table => $table->columns([
        TextColumn::make('title'),
        SelectColumn::make('author_id')->native(false)->optionsRelationship('author', 'name')->searchableOptions(),
    ]);

    $page = tpPage($this);
    $column = $page['content'][0]['columns'][1];

    expect($column['optionsSearchPath'])->toBe('__columnOptions.author_id')
        ->and($page['content'][0]['records'][0]['cells']['author_id']['optionLabel'])->toBe('Ada');

    $this->getJson('/tp/_alfakit/schema/options?'.http_build_query(['token' => $page['token'], 'component' => 'table.filters', 'path' => $column['optionsSearchPath'], 'search' => 'bo']))
        ->assertOk()
        ->assertJsonPath('options', [['value' => (string) $bob->id, 'label' => 'Bob']]);

    // A value outside the relationship query is refused; a real one is saved.
    $this->postJson('/tp/_alfakit/table/cell', ['token' => $page['token'], 'component' => 'table', 'record' => 1, 'column' => 'author_id', 'state' => 999])
        ->assertStatus(422);
    $this->postJson('/tp/_alfakit/table/cell', ['token' => $page['token'], 'component' => 'table', 'record' => 1, 'column' => 'author_id', 'state' => $bob->id])
        ->assertOk();

    expect(Post::query()->find(1)->author_id)->toBe($bob->id);
});
