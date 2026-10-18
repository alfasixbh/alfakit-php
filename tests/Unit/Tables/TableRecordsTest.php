<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Enums\PaginationMode;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

function recordsState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

function manyPosts(int $count = 25): void
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

    for ($i = 1; $i <= $count; $i++) {
        Post::query()->create(['title' => sprintf('Post %02d', $i), 'slug' => "post-{$i}", 'author_id' => $user->id, 'rating' => $i]);
    }
}

function pagedTable(PaginationMode|string|null $mode = null): Table
{
    return Table::make()->query(Post::query())->columns([TextColumn::make('title')->sortable()])->defaultSort('id')->paginationMode($mode);
}

// ---------------------------------------------------------------- Pagination modes

it('pages without a count query in simple mode', function (): void {
    manyPosts();

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $schema = pagedTable(PaginationMode::Simple)->state(recordsState(['page' => 3]))->toSchema();

    expect($schema['pagination'])->toMatchArray(['mode' => 'simple', 'currentPage' => 3, 'perPage' => 10, 'from' => 21, 'to' => 25, 'hasMorePages' => false])
        ->and($schema['pagination'])->not->toHaveKey('total')
        ->and(collect($queries)->contains(fn (string $sql): bool => str_contains(strtolower($sql), 'count(')))->toBeFalse()
        ->and(pagedTable()->simplePaginated()->state(recordsState())->toSchema()['pagination']['hasMorePages'])->toBeTrue();
});

it('pages with an opaque cursor in cursor mode', function (): void {
    manyPosts();

    $first = pagedTable('cursor')->state(recordsState())->toSchema();

    expect($first['pagination'])->toMatchArray(['mode' => 'cursor', 'perPage' => 10, 'hasMorePages' => true, 'previousCursor' => null])
        ->and(array_column($first['records'], 'key'))->toBe(range(1, 10));

    $second = pagedTable('cursor')->state(recordsState(['cursor' => $first['pagination']['nextCursor']]))->toSchema();

    expect(array_column($second['records'], 'key'))->toBe(range(11, 20))
        ->and($second['pagination']['previousCursor'])->toBeString();

    // A tampered cursor is ignored and the first page is shown.
    expect(array_column(pagedTable('cursor')->state(recordsState(['cursor' => 'garbage']))->toSchema()['records'], 'key'))->toBe(range(1, 10));
});

it('cursor-paginates relationship and aggregate sorts through select aliases', function (): void {
    $authors = collect(['Zed', 'Amy', 'Amy', 'Kim'])->map(fn (string $name, int $i) => User::query()->create(['name' => $name, 'email' => "a{$i}@example.com", 'password' => 'x']));

    foreach ($authors as $i => $author) {
        $post = Post::query()->create(['title' => "Post {$i}", 'slug' => "post-{$i}", 'author_id' => $author->id]);
        $post->comments()->createMany(array_fill(0, $i, ['author_name' => 'A', 'body' => 'x']));
    }

    $table = fn (array $query) => Table::make()->query(Post::query())
        ->columns([TextColumn::make('author.name')->sortable(), TextColumn::make('comments_count')->counts('comments')->sortable()])
        ->paginationMode(PaginationMode::Cursor)->paginationPageOptions([2])->defaultPaginationPageOption(2)->state(recordsState($query));

    $first = $table(['sort' => 'author.name'])->toSchema();
    $second = $table(['sort' => 'author.name', 'cursor' => $first['pagination']['nextCursor']])->toSchema();

    expect(array_column($first['records'], 'key'))->toBe([2, 3])
        ->and(array_column($second['records'], 'key'))->toBe([4, 1])
        ->and($second['pagination']['hasMorePages'])->toBeFalse()
        ->and(array_column($second['records'][0]['cells'], 'state'))->toBe(['Kim', '3']);

    $first = $table(['sort' => 'comments_count', 'direction' => 'desc'])->toSchema();
    $second = $table(['sort' => 'comments_count', 'direction' => 'desc', 'cursor' => $first['pagination']['nextCursor']])->toSchema();

    expect([...array_column($first['records'], 'key'), ...array_column($second['records'], 'key')])->toBe([4, 3, 2, 1]);
});

it('refuses cursor pagination over a raw sort expression with a clear message', function (): void {
    manyPosts(2);

    $table = Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')->sortable(query: fn (Builder $query, string $direction) => $query->orderByRaw("length(title) {$direction}"))])
        ->paginationMode(PaginationMode::Cursor)->state(recordsState(['sort' => 'title']));

    expect(fn () => $table->toSchema())->toThrow(LogicException::class, 'Cursor pagination needs every sort to name a column or select alias');
});

it('adds the primary key to cursor sorting so pages never overlap', function (): void {
    manyPosts(4);
    Post::query()->update(['rating' => 1]);

    $table = fn (array $query) => Table::make()->query(Post::query())->columns([TextColumn::make('rating')->sortable()])
        ->paginationMode(PaginationMode::Cursor)->paginationPageOptions([2])->defaultPaginationPageOption(2)->state(recordsState($query));

    $first = $table(['sort' => 'rating'])->toSchema();
    $second = $table(['sort' => 'rating', 'cursor' => $first['pagination']['nextCursor']])->toSchema();

    expect([...array_column($first['records'], 'key'), ...array_column($second['records'], 'key')])->toBe([1, 2, 3, 4]);
});

it('returns every record up to the requested page for load more and infinite scroll', function (string $mode): void {
    manyPosts();

    $schema = pagedTable($mode)->state(recordsState(['page' => 2]))->toSchema();

    expect(array_column($schema['records'], 'key'))->toBe(range(1, 20))
        ->and($schema['pagination'])->toMatchArray(['mode' => $mode, 'currentPage' => 2, 'perPage' => 10, 'from' => 1, 'to' => 20, 'hasMorePages' => true]);

    $last = pagedTable($mode)->state(recordsState(['page' => 3]))->toSchema();

    expect($last['records'])->toHaveCount(25)
        ->and($last['pagination']['hasMorePages'])->toBeFalse();
})->with(['load-more', 'infinite']);

it('keeps numbered pagination as the default', function (): void {
    manyPosts();

    expect(pagedTable()->state(recordsState(['page' => 2]))->toSchema()['pagination'])
        ->toMatchArray(['currentPage' => 2, 'lastPage' => 3, 'total' => 25])
        ->not->toHaveKey('mode')
        ->and(pagedTable('nonsense')->getPaginationMode())->toBe(PaginationMode::Default);
});

// ---------------------------------------------------------------- Relationship aggregates

it('adds relationship aggregates to the query and sorts by them', function (): void {
    manyPosts(3);
    $posts = Post::query()->orderBy('id')->get();
    $posts[0]->comments()->createMany([['author_name' => 'A', 'body' => 'x', 'sort' => 2], ['author_name' => 'B', 'body' => 'y', 'sort' => 6]]);
    $posts[2]->comments()->create(['author_name' => 'C', 'body' => 'z', 'sort' => 5]);

    $table = Table::make()->query(Post::query())->columns([
        TextColumn::make('title'),
        TextColumn::make('comments_count')->counts('comments')->sortable(),
        IconColumn::make('comments_exists')->exists('comments')->boolean(),
        TextColumn::make('comments_avg_sort')->avg('comments', 'sort'),
        TextColumn::make('comments_sum_sort')->sum('comments', 'sort'),
        TextColumn::make('comments_min_sort')->min('comments', 'sort'),
        TextColumn::make('comments_max_sort')->max('comments', 'sort'),
        TextColumn::make('long_comments_count')->counts(['comments as long_comments_count' => fn (Builder $query) => $query->where('sort', '>', 4)]),
    ]);

    $schema = $table->state(recordsState(['sort' => 'comments_count', 'direction' => 'desc']))->toSchema();
    $first = $schema['records'][0]['cells'];

    expect(array_column($schema['records'], 'key'))->toBe([1, 3, 2])
        ->and((int) $first['comments_count']['state'])->toBe(2)
        ->and((bool) $first['comments_exists']['state'])->toBeTrue()
        ->and((float) $first['comments_avg_sort']['state'])->toBe(4.0)
        ->and((int) $first['comments_sum_sort']['state'])->toBe(8)
        ->and((int) $first['comments_min_sort']['state'])->toBe(2)
        ->and((int) $first['comments_max_sort']['state'])->toBe(6)
        ->and((int) $first['long_comments_count']['state'])->toBe(1)
        ->and((bool) $schema['records'][2]['cells']['comments_exists']['state'])->toBeFalse();
});

it('aggregates a raw expression column and sorts and searches by it', function (): void {
    manyPosts(2);
    $posts = Post::query()->orderBy('id')->get();
    $posts[0]->comments()->createMany([['author_name' => 'A', 'body' => 'x', 'sort' => 2], ['author_name' => 'B', 'body' => 'y', 'sort' => 6]]);
    $posts[1]->comments()->create(['author_name' => 'C', 'body' => 'z', 'sort' => 10]);

    $table = fn (array $query) => Table::make()->query(Post::query())->columns([
        TextColumn::make('title'),
        TextColumn::make('double_sort')->sum('comments as double_sort', DB::raw('sort * 2'))->sortable()->searchable(isIndividual: true),
        TextColumn::make('top_sort')->max('comments as top_sort', DB::raw('sort + 1')),
    ])->state(recordsState($query));

    $schema = $table(['sort' => 'double_sort', 'direction' => 'desc'])->toSchema();

    expect(array_column($schema['records'], 'key'))->toBe([2, 1])
        ->and((int) $schema['records'][1]['cells']['double_sort']['state'])->toBe(16)
        ->and((int) $schema['records'][1]['cells']['top_sort']['state'])->toBe(7)
        ->and(array_column($table(['columnSearch' => ['double_sort' => '16']])->toSchema()['records'], 'key'))->toBe([1]);
});

it('searches aggregate columns through their sub-select', function (): void {
    manyPosts(3);
    $posts = Post::query()->orderBy('id')->get();
    $posts[0]->comments()->createMany([['author_name' => 'A', 'body' => 'x', 'sort' => 2], ['author_name' => 'B', 'body' => 'y', 'sort' => 6]]);
    $posts[1]->comments()->createMany(array_fill(0, 12, ['author_name' => 'C', 'body' => 'z', 'sort' => 3]));

    $table = fn (array $query) => Table::make()->query(Post::query())->columns([
        TextColumn::make('title')->searchable(),
        TextColumn::make('comments_count')->counts('comments')->searchable(isIndividual: true),
        TextColumn::make('comments_avg_sort')->avg('comments', 'sort')->searchable(isGlobal: false, isIndividual: true),
        TextColumn::make('long_comments_count')->counts(['comments as long_comments_count' => fn (Builder $q) => $q->where('sort', '>', 4)])->searchable(isIndividual: true),
        IconColumn::make('comments_exists')->exists('comments')->boolean()->searchable(),
    ])->state(recordsState($query));

    // Global search: every term matches the title or a searchable aggregate (default order: newest first).
    expect(array_column($table(['search' => '12'])->toSchema()['records'], 'key'))->toBe([2])
        ->and(array_column($table(['search' => 'Post 2'])->toSchema()['records'], 'key'))->toBe([2, 1])
        ->and(array_column($table(['columnSearch' => ['comments_count' => '2']])->toSchema()['records'], 'key'))->toBe([2, 1])
        ->and(array_column($table(['columnSearch' => ['comments_avg_sort' => '4']])->toSchema()['records'], 'key'))->toBe([1])
        // The constrained aggregate searches with its constraint: only post 1 has one long comment.
        ->and(array_column($table(['columnSearch' => ['long_comments_count' => '1']])->toSchema()['records'], 'key'))->toBe([1])
        ->and(array_column($table(['columnSearch' => ['comments_count' => '1']])->toSchema()['records'], 'key'))->toBe([2]);
});

// ---------------------------------------------------------------- Custom records

function planRows(): array
{
    return [
        'basic' => ['name' => 'Basic', 'price' => 10, 'tier' => 'starter'],
        'pro' => ['name' => 'Pro', 'price' => 30, 'tier' => 'business'],
        'team' => ['name' => 'Team', 'price' => 20, 'tier' => 'business'],
        'max' => ['name' => 'Max', 'price' => 99, 'tier' => 'enterprise'],
    ];
}

function plansTable(): Table
{
    return Table::make()
        ->records(fn (): array => planRows())
        ->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('price')->sortable(),
            TextColumn::make('tier')->searchable(isIndividual: true),
        ])
        ->recordUrl(fn (array $record): string => '/plans/'.strtolower($record['name']))
        ->recordActions([Action::make('edit')])
        ->bulkActions([BulkAction::make('delete')])
        ->paginationPageOptions([2, 10])
        ->defaultPaginationPageOption(2);
}

it('serialises custom array records with their keys', function (): void {
    $schema = plansTable()->state(recordsState(['sort' => 'price']))->toSchema();

    expect(array_column($schema['records'], 'key'))->toBe(['basic', 'team'])
        ->and($schema['records'][0])->toBe([
            'key' => 'basic',
            'url' => '/plans/basic',
            'selectable' => true,
            'cells' => ['name' => ['state' => 'Basic'], 'price' => ['state' => '10'], 'tier' => ['state' => 'starter']],
            'actions' => [['type' => 'Action', 'name' => 'edit', 'label' => 'Edit', 'record' => 'basic']],
        ])
        ->and($schema['pagination'])->toMatchArray(['total' => 4, 'lastPage' => 2, 'currentPage' => 1])
        ->and($schema['selectable'])->toBeTrue()
        ->and($schema['pluralModelLabel'])->toBe('records');
});

it('searches, sorts and pages custom records on the server', function (): void {
    expect(array_column(plansTable()->state(recordsState(['search' => 'BUS', 'sort' => 'price', 'direction' => 'desc']))->toSchema()['records'], 'key'))->toBe(['pro', 'team'])
        ->and(array_column(plansTable()->state(recordsState(['search' => 'pro']))->toSchema()['records'], 'key'))->toBe(['pro'])
        ->and(array_column(plansTable()->state(recordsState(['columnSearch' => ['tier' => 'business'], 'sort' => 'name']))->toSchema()['records'], 'key'))->toBe(['pro', 'team'])
        ->and(array_column(plansTable()->state(recordsState(['sort' => 'price', 'page' => 2]))->toSchema()['records'], 'key'))->toBe(['pro', 'max'])
        ->and(array_column(plansTable()->state(recordsState(['sort' => 'price', 'page' => 9]))->toSchema()['records'], 'key'))->toBe(['pro', 'max'])
        ->and(plansTable()->paginationMode(PaginationMode::Simple)->state(recordsState(['page' => 2]))->toSchema()['pagination'])->toMatchArray(['mode' => 'simple', 'hasMorePages' => false, 'from' => 3, 'to' => 4])
        ->and(array_column(plansTable()->paginationMode(PaginationMode::LoadMore)->state(recordsState(['page' => 2, 'sort' => 'name']))->toSchema()['records'], 'key'))->toBe(['basic', 'max', 'pro', 'team'])
        ->and(plansTable()->paginated(false)->state(recordsState(['sort' => 'name', 'direction' => 'desc']))->toSchema()['records'][0]['key'])->toBe('team');
});

it('requires every search term to match', function (): void {
    expect(plansTable()->state(recordsState(['search' => 'basic nope']))->toSchema()['records'])->toBe([]);
});

it('passes the table state to the records closure', function (): void {
    $received = null;

    Table::make()
        ->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage, array $filters) use (&$received): array {
            $received = compact('search', 'sortColumn', 'sortDirection', 'page', 'recordsPerPage', 'filters');

            return [];
        })
        ->columns([TextColumn::make('name')->sortable()])
        ->filters([SelectFilter::make('tier')->options(['a' => 'A'])])
        ->state(recordsState(['search' => 'x', 'sort' => 'name', 'direction' => 'desc', 'page' => 2, 'filters' => ['tier' => ['value' => 'a']]]))
        ->toSchema();

    expect($received)->toBe(['search' => 'x', 'sortColumn' => 'name', 'sortDirection' => 'desc', 'page' => 2, 'recordsPerPage' => 10, 'filters' => ['tier' => ['value' => 'a']]]);
});

it('uses a paginator returned by the records closure as is', function (): void {
    $schema = Table::make()
        ->records(fn (int $page): LengthAwarePaginator => new LengthAwarePaginator([['id' => 7, 'name' => 'Seven']], 31, 10, $page))
        ->columns([TextColumn::make('name')->searchable()])
        ->state(recordsState(['page' => 4, 'search' => 'zzz']))
        ->toSchema();

    expect(array_column($schema['records'], 'key'))->toBe([7])
        ->and($schema['pagination'])->toMatchArray(['currentPage' => 4, 'lastPage' => 4, 'total' => 31]);
});

it('keys list rows by id or position and accepts objects', function (): void {
    $schema = Table::make()
        ->records(fn () => collect([(object) ['id' => 5, 'name' => 'Obj'], ['name' => 'No id']]))
        ->columns([TextColumn::make('name')])
        ->state(recordsState())
        ->toSchema();

    expect(array_column($schema['records'], 'key'))->toBe([5, 1])
        ->and($schema['records'][0]['cells']['name']['state'])->toBe('Obj');
});

it('serialises record actions and bulk selection for custom rows', function (): void {
    $table = plansTable()
        ->recordActions([
            Action::make('edit')->hidden(fn (array $record): bool => $record['tier'] === 'starter'),
            Action::make('model')->hidden(fn (?Model $record): bool => $record === null),
        ])
        ->checkIfRecordIsSelectableUsing(fn (array $record): bool => $record['price'] > 10);

    $schema = $table->state(recordsState(['sort' => 'price']))->toSchema();

    expect($schema['selectable'])->toBeTrue()
        ->and($schema['records'][0])->not->toHaveKeys(['selectable', 'actions'])
        ->and($schema['records'][1]['selectable'])->toBeTrue()
        // `Model $record` closures get null for an array row; `$record` by name gets the row.
        ->and(array_column($schema['records'][1]['actions'], 'name'))->toBe(['edit'])
        ->and($schema['records'][1]['actions'][0]['record'])->toBe('team');
});

it('resolves custom rows by key for actions and cell edits', function (): void {
    $table = plansTable()->state(recordsState());

    expect($table->getRecord('basic'))->toBe(['name' => 'Basic', 'price' => 10, 'tier' => 'starter'])
        ->and($table->getRecord('nope'))->toBeNull()
        ->and($table->getRecord(['basic']))->toBeNull()
        ->and($table->getRecordsByKeys(['team', 'nope', 'basic'])->keys()->all())->toBe(['basic', 'team'])
        ->and($table->getAllFilteredRecords()->keys()->all())->toBe(['basic', 'pro', 'team', 'max'])
        ->and(plansTable()->state(recordsState(['search' => 'pro']))->getAllFilteredRecords()->keys()->all())->toBe(['pro']);

    $resolved = [];
    $custom = plansTable()->resolveRecordUsing(function (string $key) use (&$resolved): ?array {
        $resolved[] = $key;

        return planRows()[$key] ?? null;
    })->state(recordsState());

    expect($custom->getRecord('pro'))->toBe(['name' => 'Pro', 'price' => 30, 'tier' => 'business'])
        ->and($custom->getRecordsByKeys(['max', 'nope'])->all())->toBe(['max' => ['name' => 'Max', 'price' => 99, 'tier' => 'enterprise']])
        ->and($resolved)->toBe(['pro', 'max', 'nope']);
});

it('hands custom rows to bulk actions as a collection', function (): void {
    $received = null;
    $action = BulkAction::make('export')->action(function (Illuminate\Support\Collection $records) use (&$received): void {
        $received = $records->pluck('name')->all();
    });

    $table = plansTable()->bulkActions([$action])->state(recordsState());
    $action->context($table)->records($table->getRecordsByKeys(['pro', 'basic']))->execute();

    expect($received)->toBe(['Basic', 'Pro']);
});

it('serialises column breakpoints', function (): void {
    $schema = Table::make()->records([['id' => 1, 'name' => 'A']])->columns([
        TextColumn::make('name')->visibleFrom('md')->hiddenFrom('2xl'),
        TextColumn::make('id')->visibleFrom('huge'),
    ])->state(recordsState())->toSchema();

    expect($schema['columns'][0])->toMatchArray(['visibleFrom' => 'md', 'hiddenFrom' => '2xl'])
        ->and($schema['columns'][1])->not->toHaveKeys(['visibleFrom', 'hiddenFrom']);
});

it('drops unsafe record URLs', function (): void {
    $schema = plansTable()
        ->recordUrl(fn (array $record): string => $record['name'] === 'Basic' ? 'javascript:alert(1)' : '/plans/'.strtolower($record['name']))
        ->state(recordsState(['sort' => 'price']))
        ->toSchema();

    expect($schema['records'][0])->not->toHaveKey('url')
        ->and($schema['records'][1]['url'])->toBe('/plans/team');
});
