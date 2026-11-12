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
        ->toContain('cast("posts"."id" as text) ilike ? or cast("posts"."title" as text) ilike ?')
        ->and($query->getBindings())->toBe(['%Jo%', '%Jo%']);
});

it('leaves the comparison to the column collation elsewhere', function (): void {
    $query = Post::query();

    SearchQuery::contains($query, 'title', 'Jo');

    expect($query->toSql())->toContain('where "title" like ?')
        ->and($query->getBindings())->toBe(['%Jo%']);
});

it('lowers both sides when case-insensitivity is forced', function (): void {
    $query = Post::query();

    SearchQuery::contains($query, 'title', 'Jo', caseInsensitive: true);

    expect($query->toSql())->toContain('where lower("title") like ?')
        ->and($query->getBindings())->toBe(['%jo%']);
});

it('searches a numeric table column on PostgreSQL without an operator error', function (): void {
    $query = pgsqlPosts();

    TextColumn::make('id')->searchable()->applySearch($query, '42');

    expect($query->toSql())->toContain('cast("posts"."id" as text) ilike ?');
});
