<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;

function reorderPosts(int $count, ?callable $rating = null): void
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

    for ($i = 1; $i <= $count; $i++) {
        Post::query()->create(['title' => "Post {$i}", 'slug' => "post-{$i}", 'author_id' => $user->id, 'rating' => $rating === null ? $i : $rating($i)]);
    }
}

function reorderTable(int $page = 1, string $direction = 'asc'): Table
{
    return Table::make()
        ->query(Post::query())
        ->columns([TextColumn::make('title')])
        ->reorderable('rating', direction: $direction)
        ->state(TableState::fromRequest(Request::create('/', 'GET', ['page' => $page])));
}

/**
 * @return array<int, int>
 */
function ratingsById(): array
{
    return Post::query()->orderBy('id')->pluck('rating', 'id')->map(fn (mixed $rating): int => (int) $rating)->all();
}

it('reorders page 2 of a paginated table without touching page 1', function (): void {
    reorderPosts(15);

    $page = reorderTable(2)->toSchema();
    expect(array_column($page['records'], 'key'))->toBe([11, 12, 13, 14, 15]);

    reorderTable(2)->reorderRecords([15, 11, 12, 13, 14]);

    $ratings = ratingsById();

    expect(array_slice($ratings, 0, 10, true))->toBe(array_combine(range(1, 10), range(1, 10)))
        ->and([$ratings[15], $ratings[11], $ratings[12], $ratings[13], $ratings[14]])->toBe([11, 12, 13, 14, 15])
        ->and(array_column(reorderTable(2)->toSchema()['records'], 'key'))->toBe([15, 11, 12, 13, 14])
        ->and(array_column(reorderTable(1)->toSchema()['records'], 'key'))->toBe(range(1, 10));
});

it('renumbers the whole list when positions are tied, keeping other pages in place', function (): void {
    reorderPosts(12, fn (int $i): int => 0);

    reorderTable(2)->reorderRecords([12, 11]);

    $ratings = ratingsById();

    expect($ratings[12])->toBe(11)
        ->and($ratings[11])->toBe(12)
        ->and(array_slice($ratings, 0, 10, true))->toBe(array_combine(range(1, 10), range(1, 10)));
});

it('orders and reorders in descending direction', function (): void {
    reorderPosts(4);

    expect(array_column(reorderTable(1, 'desc')->toSchema()['records'], 'key'))->toBe([4, 3, 2, 1]);

    reorderTable(1, 'desc')->reorderRecords([1, 4, 3, 2]);

    expect(array_column(reorderTable(1, 'desc')->toSchema()['records'], 'key'))->toBe([1, 4, 3, 2])
        ->and(ratingsById())->toBe([1 => 4, 2 => 1, 3 => 2, 4 => 3]);
});
