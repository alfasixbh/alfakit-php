<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;

function reorderUser(string $email = 'cols@example.com'): User
{
    return User::query()->create(['name' => 'Cols', 'email' => $email, 'password' => 'password']);
}

function withReorderableColumns(Closure $test): mixed
{
    return Table::configureUsing(fn (Table $table) => $table->reorderableColumns(), $test);
}

it('stores a user column order in the session and applies it to the table', function (): void {
    withReorderableColumns(function (): void {
        $user = reorderUser();
        $props = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'));

        expect($props['page']['content'][0]['reorderableColumns'])->toBeTrue()
            ->and($props['alfakit']['endpoints']['tableColumns'])->toBe('http://localhost/admin/_alfakit/table/columns');

        $this->postJson('/admin/_alfakit/table/columns', [
            'token' => $props['page']['token'],
            'component' => 'table',
            'columns' => ['is_published', 'bogus', ['x'], 'title', 'title'],
        ])->assertOk()->assertExactJson(['columns' => ['is_published', 'title']]);

        $table = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['content'][0];

        expect(array_column($table['columns'], 'name'))->toBe(['is_published', 'title', 'author.name'])
            ->and($table['visibleColumns'])->toBe(['is_published', 'title', 'author.name']);

        // An empty order resets to the defined order.
        $this->postJson('/admin/_alfakit/table/columns', ['token' => $props['page']['token'], 'component' => 'table', 'columns' => []])->assertOk();

        expect(array_column(inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['content'][0]['columns'], 'name'))
            ->toBe(['title', 'author.name', 'is_published']);
    });
});

it('refuses column orders for tables without reorderable columns', function (): void {
    $user = reorderUser();
    $token = inertiaProps(inertiaGet($this->actingAs($user), '/admin/posts'))['page']['token'];

    $this->postJson('/admin/_alfakit/table/columns', ['token' => $token, 'component' => 'table', 'columns' => ['title']])
        ->assertNotFound();
});

it('binds column order requests to the token owner', function (): void {
    withReorderableColumns(function (): void {
        $token = inertiaProps(inertiaGet($this->actingAs(reorderUser()), '/admin/posts'))['page']['token'];

        $this->actingAs(reorderUser('other-cols@example.com'))
            ->postJson('/admin/_alfakit/table/columns', ['token' => $token, 'component' => 'table', 'columns' => ['title']])
            ->assertForbidden();
    });
});

it('does not reorder content layouts', function (): void {
    $table = Table::make()->reorderableColumns()->contentGrid(2)->columns([]);

    expect($table->hasReorderableColumns())->toBeFalse();
});
