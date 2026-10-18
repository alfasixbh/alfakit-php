<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableQueryString;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

function reorderingPosts(int $count): void
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

    for ($i = 1; $i <= $count; $i++) {
        Post::query()->create(['title' => "Post {$i}", 'slug' => "post-{$i}", 'author_id' => $user->id, 'rating' => $i]);
    }
}

/**
 * @param  array<string, mixed>  $query
 */
function reorderingTable(array $query = [], bool|Closure $condition = true): Table
{
    return Table::make()
        ->query(Post::query())
        ->columns([TextColumn::make('title')])
        ->reorderable('rating', $condition)
        ->state(TableState::fromRequest(Request::create('/', 'GET', $query)));
}

it('lists every record without pagination in reorder mode', function (): void {
    reorderingPosts(15);

    $schema = reorderingTable(['reordering' => '1', 'page' => 2])->toSchema();

    expect($schema['reordering'])->toBeTrue()
        ->and($schema)->not->toHaveKey('paginatedWhileReordering')
        ->and($schema)->not->toHaveKey('pagination')
        ->and(array_column($schema['records'], 'key'))->toBe(range(1, 15));

    $normal = reorderingTable()->toSchema();

    expect($normal)->not->toHaveKey('reordering')
        ->and($normal['pagination']['total'])->toBe(15)
        ->and($normal['records'])->toHaveCount(10);
});

it('keeps pages in reorder mode with paginatedWhileReordering()', function (): void {
    reorderingPosts(15);

    $schema = reorderingTable(['reordering' => '1', 'page' => 2])
        ->paginatedWhileReordering(fn (): bool => true)
        ->toSchema();

    expect($schema['reordering'])->toBeTrue()
        ->and($schema['paginatedWhileReordering'])->toBeTrue()
        ->and($schema['pagination']['currentPage'])->toBe(2)
        ->and(array_column($schema['records'], 'key'))->toBe([11, 12, 13, 14, 15]);
});

it('ignores reorder mode on a table that cannot be reordered', function (): void {
    reorderingPosts(15);

    $table = reorderingTable(['reordering' => '1'], fn (): bool => false);
    $schema = $table->toSchema();

    expect($table->isReordering())->toBeFalse()
        ->and($schema)->not->toHaveKey('reordering')
        ->and($schema['records'])->toHaveCount(10);
});

it('reads reorder mode from a prefixed key and forwards it from internal endpoints', function (): void {
    expect(TableState::fromRequest(Request::create('/', 'GET', ['posts_reordering' => '1']), 'posts')->reordering)->toBeTrue()
        ->and(TableState::fromRequest(Request::create('/', 'GET', ['reordering' => 'nope']))->reordering)->toBeFalse()
        ->and(TableState::fromRequest(Request::create('/', 'GET', ['reordering' => ['1']]))->reordering)->toBeFalse();

    $request = Request::create('/_alfakit/table/cell', 'POST', ['tableQuery' => '?posts_reordering=1&secret=x']);
    TableQueryString::apply($request);

    expect($request->query('posts_reordering'))->toBe('1')
        ->and($request->query('secret'))->toBeNull();
});

it('runs beforeReordering() and afterReordering() around saving the order', function (): void {
    reorderingPosts(3);
    $calls = [];

    reorderingTable()
        ->beforeReordering(function (array $order) use (&$calls): void {
            $calls[] = ['before', $order, (int) Post::query()->whereKey(3)->value('rating')];
        })
        ->afterReordering(function (array $order, Table $table) use (&$calls): void {
            $calls[] = ['after', $order, (int) Post::query()->whereKey(3)->value('rating'), $table->isReorderable()];
        })
        ->reorderRecords([3, 1, 2]);

    expect($calls)->toBe([
        ['before', ['3', '1', '2'], 3],
        ['after', ['3', '1', '2'], 1, true],
    ]);
});

it('runs the hooks when tied positions are renumbered', function (): void {
    reorderingPosts(3);
    Post::query()->update(['rating' => 0]);
    $after = null;

    reorderingTable()
        ->afterReordering(function (array $order) use (&$after): void {
            $after = $order;
        })
        ->reorderRecords([2, 1]);

    expect($after)->toBe(['2', '1']);
});

it('does not run the hooks when reordering is refused', function (): void {
    reorderingPosts(3);
    $called = false;

    $table = reorderingTable([], fn (): bool => false)
        ->beforeReordering(function () use (&$called): void {
            $called = true;
        });

    expect(fn () => $table->reorderRecords([3, 1, 2]))->toThrow(HttpException::class)
        ->and($called)->toBeFalse();

    $missing = reorderingTable()->beforeReordering(function () use (&$called): void {
        $called = true;
    });

    expect(fn () => $missing->reorderRecords([3, 999]))->toThrow(HttpException::class)
        ->and($called)->toBeFalse();
});
