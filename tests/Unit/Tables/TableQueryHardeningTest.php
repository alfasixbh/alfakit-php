<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\TextInputColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

function hardeningSeed(): void
{
    $zed = User::query()->create(['name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'x']);
    $amy = User::query()->create(['name' => 'Amy', 'email' => 'amy@example.com', 'password' => 'x']);

    $first = Post::query()->create(['title' => 'First', 'slug' => 'first', 'author_id' => $zed->id]);
    $second = Post::query()->create(['title' => 'Second', 'slug' => 'second', 'author_id' => $amy->id]);

    Comment::query()->create(['post_id' => $first->id, 'author_name' => 'c1', 'body' => 'one']);
    Comment::query()->create(['post_id' => $second->id, 'author_name' => 'c2', 'body' => 'two']);
}

function hardeningState(array $query): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

it('sorts by a nested relationship column', function (): void {
    hardeningSeed();

    $table = Table::make()->query(Comment::query())->columns([
        TextColumn::make('body'),
        TextColumn::make('post.author.name')->sortable(),
    ]);

    $asc = $table->state(hardeningState(['sort' => 'post.author.name', 'direction' => 'asc']))->getRecords();
    expect($asc->pluck('author_name')->all())->toBe(['c2', 'c1']);

    $desc = $table->state(hardeningState(['sort' => 'post.author.name', 'direction' => 'desc']))->getRecords();
    expect($desc->pluck('author_name')->all())->toBe(['c1', 'c2']);
});

it('aliases self-referencing tables when sorting by a relationship', function (): void {
    hardeningSeed();

    // `author_id` doubles as a "parent post" key here: posts 1 and 2 point at posts 1 and 2.
    $model = new class extends Post
    {
        protected $table = 'posts';

        public function parent(): Illuminate\Database\Eloquent\Relations\BelongsTo
        {
            return $this->belongsTo(Post::class, 'author_id');
        }
    };

    $table = Table::make()->query($model->newQuery())->columns([TextColumn::make('parent.title')->sortable()]);

    expect($table->state(hardeningState(['sort' => 'parent.title', 'direction' => 'desc']))->getRecords()->pluck('title')->all())->toBe(['Second', 'First']);
});

it('ignores array values in the table query string', function (): void {
    hardeningSeed();

    $state = hardeningState([
        'search' => ['x'],
        'sort' => ['title'],
        'direction' => ['desc'],
        'perPage' => ['1'],
        'page' => ['2'],
        'columns' => [['title'], 'title'],
        'columnSearch' => ['title' => ['x'], 'body' => 'one'],
        'filters' => ['status' => 'oops', 'author' => ['value' => 1]],
    ]);

    expect($state->search)->toBeNull()
        ->and($state->sortColumn)->toBeNull()
        ->and($state->sortDirection)->toBeNull()
        ->and($state->perPage)->toBeNull()
        ->and($state->page)->toBe(1)
        ->and($state->toggledColumns)->toBe(['title'])
        ->and($state->columnSearches)->toBe(['body' => 'one'])
        ->and($state->filters)->toBe(['author' => ['value' => 1]]);

    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')->searchable()->sortable()])->state($state)->toSchema();

    expect($schema['records'])->toHaveCount(2);
});

it('neither searches nor sorts by hidden columns', function (): void {
    hardeningSeed();

    $table = Table::make()->query(Post::query())->columns([
        TextColumn::make('title')->sortable(),
        TextColumn::make('slug')->searchable(isIndividual: true)->sortable()->hidden(),
    ])->defaultSort('title');

    expect($table->state(hardeningState(['search' => 'second']))->getRecords())->toHaveCount(2)
        ->and($table->state(hardeningState(['columnSearch' => ['slug' => 'second']]))->getRecords())->toHaveCount(2)
        ->and($table->state(hardeningState(['sort' => 'slug', 'direction' => 'desc']))->getRecords()->pluck('title')->all())->toBe(['First', 'Second'])
        ->and($table->isSearchable())->toBeFalse();
});

it('shows the last page when the requested page is past the end', function (): void {
    hardeningSeed();

    $table = Table::make()->query(Post::query())->columns([TextColumn::make('title')])->defaultSort('title')
        ->paginationPageOptions([1, 10])->defaultPaginationPageOption(1);

    $schema = $table->state(hardeningState(['page' => 99]))->toSchema();

    expect(array_column($schema['records'], 'key'))->toHaveCount(1)
        ->and($schema['pagination']['currentPage'])->toBe(2)
        ->and($schema['pagination']['lastPage'])->toBe(2);
});

it('excludes trashed records from inline edits but not from actions', function (): void {
    hardeningSeed();
    $post = Post::query()->firstOrFail();
    $post->delete();

    $table = Table::make()->query(Post::query())->columns([TextColumn::make('title')]);

    expect($table->getRecord($post->id)?->is($post))->toBeTrue()
        ->and($table->getRecord($post->id, withTrashed: false))->toBeNull()
        ->and($table->getRecord([$post->id]))->toBeNull();
});

it('rejects non-scalar values for inline edits', function (): void {
    hardeningSeed();
    $post = Post::query()->firstOrFail();

    $column = TextInputColumn::make('title');
    Table::make()->query(Post::query())->columns([$column]);

    expect(fn () => $column->updateState($post, ['nope']))->toThrow(HttpException::class);
    expect($post->fresh()->title)->toBe('First');
});
