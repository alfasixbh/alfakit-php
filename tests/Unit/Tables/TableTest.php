<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\ToggleColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Filters\TrashedFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

function seedPosts(): void
{
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);
    $news = Category::query()->create(['name' => 'News', 'slug' => 'news']);
    $art = Category::query()->create(['name' => 'Art', 'slug' => 'art']);

    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'author_id' => $ada->id, 'category_id' => $news->id, 'is_published' => true, 'rating' => 5, 'status' => 'published']);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta', 'author_id' => $bob->id, 'category_id' => $art->id, 'is_published' => false, 'rating' => 3, 'status' => 'draft']);
    Post::query()->create(['title' => 'Gamma', 'slug' => 'gamma', 'author_id' => $ada->id, 'category_id' => $art->id, 'is_published' => true, 'rating' => 1, 'status' => 'draft']);
}

function postsTable(): Table
{
    return Table::make()
        ->query(Post::query())
        ->columns([
            TextColumn::make('title')->searchable()->sortable(),
            TextColumn::make('author.name')->searchable()->sortable(),
            TextColumn::make('rating')->sortable()->numeric(),
            ToggleColumn::make('is_published'),
            TextColumn::make('status')->badge()->toggleable(isToggledHiddenByDefault: true),
        ])
        ->filters([
            SelectFilter::make('category_id')->relationship('category', 'name'),
            TernaryFilter::make('is_published'),
            Filter::make('top')->query(fn (Builder $q) => $q->where('rating', '>=', 4)),
            TrashedFilter::make(),
        ])
        ->actions([Action::make('edit')->url(fn (Post $record) => "/posts/{$record->id}/edit")])
        ->bulkActions([BulkAction::make('delete')->action(fn ($records) => $records->each->delete())])
        ->defaultSort('title');
}

function stateFrom(array $query): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

it('serialises columns, records and pagination with default sort', function (): void {
    seedPosts();

    $schema = postsTable()->state(stateFrom([]))->toSchema();

    expect($schema['type'])->toBe('Table')
        ->and(array_column($schema['columns'], 'name'))->toBe(['title', 'author.name', 'rating', 'is_published', 'status'])
        ->and($schema['visibleColumns'])->toBe(['title', 'author.name', 'rating', 'is_published'])
        ->and($schema['columns'][1]['label'])->toBe('Author')
        ->and(array_column($schema['records'], 'key'))->toBe([1, 2, 3])
        ->and($schema['records'][0]['cells']['author.name']['state'])->toBe('Ada')
        ->and($schema['records'][0]['cells']['is_published']['state'])->toBeTrue()
        ->and($schema['columns'][3]['editable'])->toBeTrue()
        ->and($schema['records'][0]['actions'][0]['url'])->toBe('/posts/1/edit')
        ->and($schema['pagination']['total'])->toBe(3)
        ->and($schema['sort'])->toBe(['column' => 'title', 'direction' => 'asc'])
        ->and($schema['searchable'])->toBeTrue()
        ->and($schema['selectable'])->toBeTrue();
});

it('renders boolean icon columns', function (): void {
    seedPosts();

    $table = Table::make()->query(Post::query())->columns([IconColumn::make('is_published')->boolean()->falseIcon('ban')]);
    $records = $table->state(stateFrom([]))->toSchema()['records'];

    // No sort configured: newest first (Gamma, Beta, Alpha).
    expect($records[1]['cells']['is_published'])->toMatchArray(['state' => false, 'icon' => 'ban', 'color' => 'danger'])
        ->and($records[0]['cells']['is_published'])->toMatchArray(['state' => true, 'icon' => 'circle-check', 'color' => 'success']);
});

it('searches across columns including relationships', function (): void {
    seedPosts();

    $titles = fn (array $q) => postsTable()->state(stateFrom($q))->getRecords()->pluck('title')->all();

    expect($titles(['search' => 'bob']))->toBe(['Beta'])
        ->and($titles(['search' => 'gam']))->toBe(['Gamma'])
        ->and($titles(['search' => 'ada']))->toBe(['Alpha', 'Gamma']);
});

it('sorts by plain and relationship columns', function (): void {
    seedPosts();

    $titles = fn (array $q) => postsTable()->state(stateFrom($q))->getRecords()->pluck('title')->all();

    expect($titles(['sort' => 'rating', 'direction' => 'desc']))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($titles(['sort' => 'rating', 'direction' => 'asc']))->toBe(['Gamma', 'Beta', 'Alpha'])
        ->and($titles(['sort' => 'author.name', 'direction' => 'desc']))->toBe(['Beta', 'Gamma', 'Alpha']); // Ties break by key, in the sort's direction (`defaultKeySort()`).
});

it('reports the sort the query used, not an ignored one from the URL', function (): void {
    seedPosts();

    $sort = fn (array $q, ?Table $table = null) => ($table ?? postsTable())->state(stateFrom($q))->toSchema()['sort'] ?? null;

    expect($sort(['sort' => 'rating', 'direction' => 'desc']))->toBe(['column' => 'rating', 'direction' => 'desc'])
        // Not sortable, unknown, or not a column at all: the default sort applies.
        ->and($sort(['sort' => 'is_published', 'direction' => 'desc']))->toBe(['column' => 'title', 'direction' => 'asc'])
        ->and($sort(['sort' => 'secret', 'direction' => 'desc']))->toBe(['column' => 'title', 'direction' => 'asc'])
        ->and($sort(['sort' => 'rating'], Table::make()->query(Post::query())->columns([TextColumn::make('rating')->sortable()->hidden()])))->toBeNull()
        ->and($sort(['sort' => 'title'], Table::make()->query(Post::query())->columns([TextColumn::make('title')])->defaultSort('title', 'desc')))->toBe(['column' => 'title', 'direction' => 'desc']);
});

it('applies select, ternary, custom and trashed filters with indicators', function (): void {
    seedPosts();
    Post::query()->where('slug', 'gamma')->first()->delete();

    $titles = fn (array $q) => postsTable()->state(stateFrom($q))->getRecords()->pluck('title')->all();

    expect($titles(['filters' => ['category_id' => ['value' => '2']]]))->toBe(['Beta'])
        ->and($titles(['filters' => ['is_published' => ['value' => '0']]]))->toBe(['Beta'])
        ->and($titles(['filters' => ['top' => ['isActive' => '1']]]))->toBe(['Alpha'])
        ->and($titles(['filters' => ['trashed' => ['value' => '1']]]))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($titles(['filters' => ['trashed' => ['value' => '0']]]))->toBe(['Gamma'])
        ->and($titles([]))->toBe(['Alpha', 'Beta']);

    $schema = postsTable()->state(stateFrom(['filters' => ['category_id' => ['value' => '2'], 'is_published' => ['value' => '1']]]))->toSchema();

    expect($schema['filterIndicators'])->toBe([
        ['filter' => 'category_id', 'label' => 'Category: Art'],
        ['filter' => 'is_published', 'label' => 'Is published: Yes'],
    ])->and($schema['filters'][0]['schema'][0]['statePath'])->toBe('category_id.value');
});

it('paginates and respects page options', function (): void {
    seedPosts();

    $table = postsTable()->paginationPageOptions([2, 5])->defaultPaginationPageOption(2);

    $page1 = $table->state(stateFrom([]))->toSchema();
    $page2 = postsTable()->paginationPageOptions([2, 5])->defaultPaginationPageOption(2)->state(stateFrom(['page' => 2]))->toSchema();
    $all = postsTable()->paginationPageOptions([2, 'all'])->state(stateFrom(['perPage' => 'all']))->toSchema();

    expect($page1['pagination'])->toMatchArray(['currentPage' => 1, 'lastPage' => 2, 'perPage' => 2, 'total' => 3])
        ->and(count($page1['records']))->toBe(2)
        ->and(array_column($page2['records'], 'key'))->toBe([3])
        ->and(count($all['records']))->toBe(3)
        ->and($all['pagination']['perPage'])->toBe('all');
});

it('toggles columns via state and prefixes keys with an identifier', function (): void {
    seedPosts();

    $request = Request::create('/', 'GET', ['posts_columns' => ['title', 'status'], 'posts_search' => 'alpha']);
    $table = postsTable()->queryStringIdentifier('posts')->state(TableState::fromRequest($request, 'posts'));
    $schema = $table->toSchema();

    expect($schema['visibleColumns'])->toBe(['title', 'author.name', 'rating', 'is_published', 'status'])
        ->and($schema['records'])->toHaveCount(1)
        ->and($schema['records'][0]['cells']['status']['state'])->toBe('published');
});

it('updates inline-editable column state with validation', function (): void {
    seedPosts();

    $column = postsTable()->getColumn('is_published');
    $post = Post::query()->first();

    expect($column)->toBeInstanceOf(ToggleColumn::class);

    $column->updateState($post, '0');

    expect($post->fresh()->is_published)->toBeFalse();

    $disabled = ToggleColumn::make('is_published')->disabled();

    expect(fn () => $disabled->updateState($post, true))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('resolves records including trashed and finds actions', function (): void {
    seedPosts();
    $table = postsTable();
    Post::query()->find(2)->delete();

    expect($table->getRecord(2))->not->toBeNull()
        ->and($table->getAction('edit'))->toBeInstanceOf(Action::class)
        ->and($table->getBulkAction('delete'))->toBeInstanceOf(BulkAction::class)
        ->and($table->getAction('nope'))->toBeNull();
});

it('omits deferred records on the first load and loads them on a partial reload', function (): void {
    seedPosts();

    $first = postsTable()->deferLoading()->state(stateFrom([]))->toSchema();

    expect($first['deferLoading'])->toBeTrue()
        ->and($first)->not->toHaveKey('records')
        ->and($first)->not->toHaveKey('pagination');

    $request = Request::create('/', 'GET');
    $request->headers->set('X-Inertia-Partial-Component', 'alfakit/page');

    $partial = postsTable()->deferLoading()->state(TableState::fromRequest($request))->toSchema();

    expect($partial)->not->toHaveKey('deferLoading')
        ->and($partial['records'])->toHaveCount(3)
        ->and($partial['pagination'])->not->toBeNull();
});

it('serialises filter column spans as breakpoints', function (): void {
    expect(TernaryFilter::make('is_published')->columnSpan(2)->toSchema()['columnSpan'])->toBe(['default' => 2])
        ->and(TernaryFilter::make('is_published')->columnSpan('full')->toSchema()['columnSpan'])->toBe(['default' => 'full'])
        ->and(TernaryFilter::make('is_published')->columnSpan(['md' => 2])->toSchema()['columnSpan'])->toBe(['md' => 2])
        ->and(TernaryFilter::make('is_published')->toSchema())->not->toHaveKey('columnSpan');
});
