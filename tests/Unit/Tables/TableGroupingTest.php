<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\Summarizers\Sum;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Grouping\Group;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;

if (! function_exists('seedSummaryPosts')) {
    function seedSummaryPosts(): void
    {
        $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);
        $news = Category::query()->create(['name' => 'News', 'slug' => 'news']);

        foreach ([['Alpha', 5, 'published', $ada, '2026-01-05 10:00:00'], ['Beta', 3, 'draft', $bob, '2026-01-05 18:00:00'], ['Gamma', 1, 'draft', $ada, '2026-02-01 09:00:00'], ['Delta', null, 'published', null, null]] as [$title, $rating, $status, $author, $published]) {
            Post::query()->create(['title' => $title, 'slug' => strtolower($title), 'rating' => $rating, 'status' => $status, 'author_id' => $author?->id, 'category_id' => $news->id, 'is_published' => $status === 'published', 'published_at' => $published]);
        }
    }
}

function groupingState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

function groupedPostsTable(): Table
{
    return Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('rating')->summarize(Sum::make())])
        ->groups([
            Group::make('status')->collapsible(),
            Group::make('author.name')->label('Author'),
            Group::make('published_at')->date()->titlePrefixedWithLabel(false),
        ])
        ->defaultSort('title');
}

it('groups rows by the default group with titles, keys and group summaries', function (): void {
    seedSummaryPosts();

    $schema = groupedPostsTable()->defaultGroup('status')->state(groupingState())->toSchema();

    expect(array_column($schema['records'], 'group'))->toBe(['draft', 'draft', 'published', 'published'])
        ->and(array_map(static fn (array $record): string => $record['cells']['title']['state'], $schema['records']))->toBe(['Beta', 'Gamma', 'Alpha', 'Delta'])
        ->and($schema['grouping'])->toBe(['group' => 'status', 'label' => 'Status', 'direction' => 'asc', 'collapsible' => true])
        ->and(array_column($schema['groups'], 'id'))->toBe(['status', 'author.name', 'published_at'])
        ->and($schema['recordGroups'][0])->toBe(['key' => 'draft', 'title' => 'Status: draft', 'summary' => ['rating' => [['label' => 'Sum', 'value' => '4']]], 'collapsible' => true])
        ->and($schema['recordGroups'][1]['summary']['rating'][0]['value'])->toBe('5');
});

it('lets users pick a group and direction, and turn a default group off', function (): void {
    seedSummaryPosts();

    $byAuthor = groupedPostsTable()->defaultGroup('status')->state(groupingState(['group' => 'author.name', 'groupDirection' => 'desc']))->toSchema();

    expect(array_column($byAuthor['records'], 'group'))->toBe(['Bob', 'Ada', 'Ada', ''])
        ->and(array_column($byAuthor['recordGroups'], 'title'))->toBe(['Author: Bob', 'Author: Ada', 'Author: No value'])
        ->and($byAuthor['recordGroups'][2]['summary']['rating'][0]['value'])->toBe('0');

    $none = groupedPostsTable()->defaultGroup('status')->state(groupingState(['group' => '']))->toSchema();

    expect($none)->not->toHaveKey('grouping')
        ->and($none)->not->toHaveKey('recordGroups')
        ->and($none['records'][0])->not->toHaveKey('group');

    // Unknown groups fall back to the default.
    $unknown = groupedPostsTable()->defaultGroup('status')->state(groupingState(['group' => 'password']))->toSchema();

    expect($unknown['grouping']['group'])->toBe('status');
});

it('groups dates by day in the panel timezone', function (): void {
    seedSummaryPosts();

    $schema = groupedPostsTable()->state(groupingState(['group' => 'published_at']))->toSchema();

    expect(array_column($schema['recordGroups'], 'key'))->toBe(['', '2026-01-05', '2026-02-01'])
        ->and(array_column($schema['recordGroups'], 'title'))->toBe(['No value', 'Jan 5, 2026', 'Feb 1, 2026'])
        ->and($schema['recordGroups'][1]['summary']['rating'][0]['value'])->toBe('8');
});

it('hides the group picker and keeps the default group when settings are hidden', function (): void {
    seedSummaryPosts();

    $schema = groupedPostsTable()->defaultGroup('status')->groupingSettingsHidden()->state(groupingState(['group' => 'author.name']))->toSchema();

    expect($schema)->not->toHaveKey('groups')
        ->and($schema['grouping']['group'])->toBe('status');
});

it('shows only group headings and summaries for groups-only tables', function (): void {
    seedSummaryPosts();

    $schema = groupedPostsTable()->defaultGroup('status')->groupsOnly()->collapsedGroupsByDefault()->paginationPageOptions([1])->defaultPaginationPageOption(1)->state(groupingState())->toSchema();

    expect($schema['records'])->toBe([])
        ->and($schema)->not->toHaveKey('pagination')
        ->and($schema['grouping']['groupsOnly'])->toBeTrue()
        ->and($schema['grouping']['collapsedByDefault'])->toBeTrue()
        ->and(array_column($schema['recordGroups'], 'key'))->toBe(['draft', 'published'])
        ->and($schema['summaries'])->not->toHaveKey('page');
});

it('uses custom titles, descriptions and keys', function (): void {
    seedSummaryPosts();

    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')])
        ->defaultGroup(
            Group::make('rating')
                ->getKeyFromRecordUsing(fn (Post $record): string => ($record->rating ?? 0) >= 3 ? 'high' : 'low')
                ->getTitleFromRecordUsing(fn (Post $record): string => ($record->rating ?? 0) >= 3 ? 'High' : 'Low')
                ->getDescriptionFromRecordUsing(fn (): string => 'By rating')
                ->orderQueryUsing(fn ($query, string $direction) => $query->orderByRaw('coalesce(rating, 0) >= 3 '.$direction))
                ->titlePrefixedWithLabel(false),
        )
        ->state(groupingState())->toSchema();

    expect(array_column($schema['recordGroups'], 'title'))->toBe(['Low', 'High'])
        ->and($schema['recordGroups'][0]['description'])->toBe('By rating');
});

// ---------------------------------------------------------------- Custom `records()`

function groupedArrayTable(): Table
{
    return Table::make()
        ->records(fn (): array => [
            1 => ['title' => 'First item', 'status' => 'active'],
            2 => ['title' => 'Second item', 'status' => 'inactive'],
            3 => ['title' => 'Third item', 'status' => 'active'],
            4 => ['title' => 'Fourth item', 'status' => null],
            5 => ['title' => 'Fifth item', 'status' => 'active'],
        ])
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('status')])
        ->groups([Group::make('status')]);
}

it('reads keys, titles and descriptions of array records', function (): void {
    $row = ['__key' => '1', 'name' => 'John', 'status' => 'active'];

    expect(Group::make('status')->getKey($row))->toBe('active')
        ->and(Group::make('status')->titlePrefixedWithLabel(false)->getTitle($row))->toBe('active')
        ->and(Group::make('status')->getDescriptionFromRecordUsing(fn (array $record): string => 'User: '.$record['name'])->getDescription($row))->toBe('User: John')
        ->and(Group::make('status')->getKeyFromRecordUsing(fn (array $record): string => strtoupper($record['status']))->getKey($row))->toBe('ACTIVE')
        ->and(Group::make('status')->titlePrefixedWithLabel(false)->getTitleFromRecordUsing(fn (array $record): string => 'Status: '.ucfirst($record['status']))->getTitle($row))->toBe('Status: Active');
});

it('groups custom array records, keeping each group together', function (): void {
    $schema = groupedArrayTable()->state(groupingState(['group' => 'status', 'sort' => 'title']))->toSchema();

    expect(array_column($schema['records'], 'group'))->toBe(['', 'active', 'active', 'active', 'inactive'])
        ->and(array_column($schema['records'], 'key'))->toBe([4, 5, 1, 3, 2])
        ->and($schema['grouping'])->toMatchArray(['group' => 'status', 'direction' => 'asc'])
        ->and(array_column($schema['recordGroups'], 'key'))->toBe(['', 'active', 'inactive'])
        ->and($schema['recordGroups'][1]['title'])->toBe('Status: active');

    $desc = groupedArrayTable()->state(groupingState(['group' => 'status', 'groupDirection' => 'desc']))->toSchema();

    expect(array_column($desc['recordGroups'], 'key'))->toBe(['inactive', 'active', ''])
        ->and(array_column(groupedArrayTable()->groupsOnly()->state(groupingState(['group' => 'status']))->toSchema()['recordGroups'], 'key'))->toBe(['', 'active', 'inactive']);
});
