<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

function persistedPostsTable(Closure $requests): void
{
    Table::configureUsing(
        static fn (Table $table): Table => $table->persistSearchInSession()->persistFiltersInSession()->persistSortInSession(),
        $requests,
    );
}

function persistenceUser(): User
{
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'is_published' => true]);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta', 'is_published' => false]);

    return $user;
}

it('restores search, filters and sort from the session on a later visit', function (): void {
    $this->actingAs(persistenceUser());

    persistedPostsTable(function (): void {
        inertiaGet($this, '/admin/posts?search=Alp&sort=title&direction=desc&filters[is_published][value]=1')->assertOk();

        $table = inertiaProps(inertiaGet($this, '/admin/posts'))['page']['content'][0];

        expect($table['search'])->toBe('Alp')
            ->and($table['sort'])->toBe(['column' => 'title', 'direction' => 'desc'])
            ->and($table['filterState'])->toBe(['is_published' => ['value' => '1']])
            ->and($table['persistedState'])->toBe(['filters', 'search', 'sort'])
            ->and(array_column($table['records'], 'key'))->toBe([1]);
    });
});

it('stores cleared values sent as empty keys', function (): void {
    $this->actingAs(persistenceUser());

    persistedPostsTable(function (): void {
        inertiaGet($this, '/admin/posts?search=Alp&filters[is_published][value]=1')->assertOk();
        inertiaGet($this, '/admin/posts?search=&filters=')->assertOk();

        $table = inertiaProps(inertiaGet($this, '/admin/posts'))['page']['content'][0];

        expect($table)->not->toHaveKey('search')
            ->and($table)->not->toHaveKey('filterState')
            ->and($table['records'])->toHaveCount(2);
    });
});

it('lets the query string win over the session and keeps other keys restored', function (): void {
    $this->actingAs(persistenceUser());

    persistedPostsTable(function (): void {
        inertiaGet($this, '/admin/posts?search=Alp&sort=title&direction=desc')->assertOk();

        $table = inertiaProps(inertiaGet($this, '/admin/posts?search=Bet'))['page']['content'][0];

        expect($table['search'])->toBe('Bet')
            ->and($table['sort'])->toBe(['column' => 'title', 'direction' => 'desc']);
    });
});

it('scopes persisted state to the user', function (): void {
    $ada = persistenceUser();
    $bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

    persistedPostsTable(function () use ($ada, $bob): void {
        $this->actingAs($ada);
        inertiaGet($this, '/admin/posts?search=Alp')->assertOk();

        $this->actingAs($bob);
        $table = inertiaProps(inertiaGet($this, '/admin/posts'))['page']['content'][0];

        expect($table)->not->toHaveKey('search');
    });
});

it('does not persist anything unless asked to', function (): void {
    $this->actingAs(persistenceUser());

    inertiaGet($this, '/admin/posts?search=Alp')->assertOk();

    $table = inertiaProps(inertiaGet($this, '/admin/posts'))['page']['content'][0];

    expect($table)->not->toHaveKey('search')
        ->and($table)->not->toHaveKey('persistedState');
});
