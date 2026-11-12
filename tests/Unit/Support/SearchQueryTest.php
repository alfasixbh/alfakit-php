<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Support\SearchQuery;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/**
 * A query on a PostgreSQL connection: compiling its SQL needs no server.
 */
function pgsqlPosts(): Illuminate\Database\Eloquent\Builder
{
    config()->set('database.connections.pgsql_search', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'database' => 'alfakit',
        'username' => 'alfakit',
        'password' => '',
    ]);

    return (new Post)->setConnection('pgsql_search')->newQuery();
}

it('casts the column to text and ignores case on PostgreSQL', function (): void {
    $query = pgsqlPosts();

    SearchQuery::contains($query, 'posts.id', 'Jo');
    SearchQuery::contains($query, 'posts.title', 'Jo', or: true);

    expect($query->toSql())
        ->toContain('cast("posts"."id" as text) ilike ? escape \'!\' or cast("posts"."title" as text) ilike ? escape \'!\'')
        ->and($query->getBindings())->toBe(['%Jo%', '%Jo%']);
});

it('leaves the comparison to the column collation elsewhere', function (): void {
    $query = Post::query();

    SearchQuery::contains($query, 'title', 'Jo');

    // MySQL and MariaDB quote names with backticks.
    expect(str_replace('`', '"', $query->toSql()))->toContain('where "title" like ? escape \'!\'')
        ->and($query->getBindings())->toBe(['%Jo%']);
})->skip(fn (): bool => Illuminate\Support\Facades\DB::getDriverName() === 'pgsql', 'Asserts the SQL of the other drivers.');

it('lowers both sides when case-insensitivity is forced', function (): void {
    $query = Post::query();

    SearchQuery::contains($query, 'title', 'Jo', caseInsensitive: true);

    expect(str_replace('`', '"', $query->toSql()))->toContain('where lower("title") like ? escape \'!\'')
        ->and($query->getBindings())->toBe(['%jo%']);
})->skip(fn (): bool => Illuminate\Support\Facades\DB::getDriverName() === 'pgsql', 'Asserts the SQL of the other drivers.');

it('searches a numeric table column on PostgreSQL without an operator error', function (): void {
    $query = pgsqlPosts();

    TextColumn::make('id')->searchable()->applySearch($query, '42');

    expect($query->toSql())->toContain('cast("posts"."id" as text) ilike ?');
});

it('treats % and _ in the search as text', function (): void {
    Post::query()->create(['title' => '50% off', 'slug' => 'a']);
    Post::query()->create(['title' => '500 off', 'slug' => 'b']);
    Post::query()->create(['title' => 'snake_case', 'slug' => 'c']);
    Post::query()->create(['title' => 'snakeXcase', 'slug' => 'd']);

    $find = function (string $search): array {
        $query = Post::query();
        SearchQuery::contains($query, 'title', $search);

        return $query->orderBy('slug')->pluck('slug')->all();
    };

    expect($find('50%'))->toBe(['a'])
        ->and($find('snake_c'))->toBe(['c'])
        ->and($find('off'))->toBe(['a', 'b']);
});
