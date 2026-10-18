<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Schemas\Components\EmbeddedTable;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages\ListPosts;

afterEach(function (): void {
    ListPosts::$contentUsing = null;
});

function listPageBlocks(AlfaSix\Alfakit\Tests\TestCase $test): array
{
    $test->actingAs(User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'password']));

    return array_map(
        static fn (array $block): string => $block['key'] ?? $block['type'],
        inertiaProps(inertiaGet($test, '/admin/posts'))['page']['content'],
    );
}

it('renders only the table when the list page has no content', function (): void {
    expect(listPageBlocks($this))->toBe(['table']);
});

it('places content components around an EmbeddedTable marker', function (): void {
    ListPosts::$contentUsing = fn (Schema $schema): Schema => $schema->components([
        Text::make('Above the table'),
        EmbeddedTable::make(),
        Text::make('Below the table'),
    ]);

    expect(listPageBlocks($this))->toBe(['content-before', 'table', 'content-after']);
});

it('puts content above the table when there is no marker', function (): void {
    ListPosts::$contentUsing = fn (Schema $schema): Schema => $schema->components([Text::make('Intro')]);

    expect(listPageBlocks($this))->toBe(['content-before', 'table']);
});
