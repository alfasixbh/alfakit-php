<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Infolists\Components\ViewEntry;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Tables\Columns\ColumnGroup;
use AlfaSix\Alfakit\Tables\Columns\Layout\Grid;
use AlfaSix\Alfakit\Tables\Columns\Layout\Panel;
use AlfaSix\Alfakit\Tables\Columns\Layout\Split;
use AlfaSix\Alfakit\Tables\Columns\Layout\Stack;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\ViewColumn;
use AlfaSix\Alfakit\Tables\Enums\RecordActionsPosition;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\Request;

function layoutPosts(): void
{
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);

    foreach (['Alpha', 'Beta', 'Gamma'] as $index => $title) {
        Post::query()->create(['title' => $title, 'slug' => strtolower($title), 'author_id' => $ada->id, 'rating' => $index + 1, 'status' => 'draft']);
    }
}

function layoutState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

it('flattens split, stack and panel layouts into searchable, sortable columns', function (): void {
    layoutPosts();

    $table = Table::make()->query(Post::query())->columns([
        Split::make([
            TextColumn::make('title')->searchable()->sortable(),
            Stack::make([
                TextColumn::make('author.name'),
                TextColumn::make('status'),
            ])->space(2)->alignment(Alignment::End)->grow(false),
        ])->from('md'),
        Panel::make([
            Grid::make(['default' => 1, 'md' => 2])->schema([TextColumn::make('rating')]),
        ])->collapsible(),
    ]);

    $schema = $table->state(layoutState(['search' => 'gam', 'sort' => 'title']))->toSchema();

    expect(array_keys($table->getColumns()))->toBe(['title', 'author.name', 'status', 'rating'])
        ->and(array_column($schema['records'], 'key'))->toBe([3])
        ->and($schema['records'][0]['cells']['author.name']['state'])->toBe('Ada')
        ->and($schema['contentLayout'])->toBe([
            [
                'type' => 'Split',
                'schema' => [
                    ['type' => 'Column', 'name' => 'title'],
                    ['type' => 'Stack', 'schema' => [['type' => 'Column', 'name' => 'author.name'], ['type' => 'Column', 'name' => 'status']], 'grow' => false, 'space' => 2, 'alignment' => 'end'],
                ],
                'from' => 'md',
            ],
            [
                'type' => 'Panel',
                'schema' => [['type' => 'Grid', 'schema' => [['type' => 'Column', 'name' => 'rating']], 'columns' => ['default' => 1, 'md' => 2]]],
                'collapsible' => true,
                'collapsed' => true,
            ],
        ]);
});

it('leaves hidden columns and layouts out of the layout tree', function (): void {
    $table = Table::make()->query(Post::query())->columns([
        Stack::make([TextColumn::make('title'), TextColumn::make('slug')->hidden()]),
        Stack::make([TextColumn::make('status')])->hidden(),
        Panel::make([TextColumn::make('rating')])->collapsible()->collapsed(false)->visibleFrom('md')->hiddenFrom('nope'),
    ]);

    expect($table->toSchema()['contentLayout'])->toBe([
        ['type' => 'Stack', 'schema' => [['type' => 'Column', 'name' => 'title']]],
        ['type' => 'Panel', 'schema' => [['type' => 'Column', 'name' => 'rating']], 'visibleFrom' => 'md', 'collapsible' => true],
    ]);
});

it('serialises a content grid, implying a content layout', function (): void {
    $plain = Table::make()->query(Post::query())->columns([TextColumn::make('title')]);

    expect($plain->toSchema())->not->toHaveKey('contentLayout')
        ->and($plain->contentGrid(3)->toSchema())->toMatchArray([
            'contentGrid' => ['default' => 1, 'lg' => 3],
            'contentLayout' => [['type' => 'Column', 'name' => 'title']],
        ])
        ->and($plain->contentGrid(['md' => 2, 'xl' => 3])->toSchema()['contentGrid'])->toBe(['md' => 2, 'xl' => 3]);
});

it('serialises column groups while keeping their columns in the table', function (): void {
    layoutPosts();

    $table = Table::make()->query(Post::query())->columns([
        TextColumn::make('title'),
        ColumnGroup::make('Details', [
            TextColumn::make('status'),
            TextColumn::make('rating')->sortable(),
            TextColumn::make('slug')->hidden(),
        ])->alignCenter()->wrapHeader(),
        ColumnGroup::make('Empty', [TextColumn::make('content')->hidden()]),
    ]);

    $schema = $table->state(layoutState(['sort' => 'rating', 'direction' => 'desc']))->toSchema();

    expect(array_column($schema['columns'], 'name'))->toBe(['title', 'status', 'rating'])
        ->and($schema['columnGroups'])->toBe([['label' => 'Details', 'columns' => ['status', 'rating'], 'alignment' => 'center', 'wrapHeader' => true]])
        ->and($schema)->not->toHaveKey('contentLayout')
        ->and(array_column($schema['records'], 'key'))->toBe([3, 2, 1]);
});

it('serialises the record actions position from the enum or a string', function (): void {
    $table = Table::make()->query(Post::query())->columns([TextColumn::make('title')]);

    expect($table->toSchema()['recordActionsPosition'])->toBe('after-columns')
        ->and($table->recordActions([Action::make('edit')], RecordActionsPosition::BeforeCells)->toSchema()['recordActionsPosition'])->toBe('before-cells')
        ->and($table->recordActionsPosition('before-columns')->getRecordActionsPosition())->toBe(RecordActionsPosition::BeforeColumns)
        ->and($table->recordActionsPosition('sideways')->getRecordActionsPosition())->toBe(RecordActionsPosition::AfterColumns);
});

it('renders view columns with per-record props', function (): void {
    layoutPosts();

    $schema = Table::make()->query(Post::query())->defaultSort('id')->columns([
        ViewColumn::make('status')->view('StatusSwitcher')->viewData(fn (Post $record, string $state): array => ['id' => $record->id, 'upper' => strtoupper($state)]),
        ViewColumn::make('title')->component('Plain'),
    ])->toSchema();

    expect($schema['columns'][0])->toMatchArray(['type' => 'ViewColumn', 'component' => 'StatusSwitcher'])
        ->and($schema['records'][0]['cells']['status'])->toBe(['state' => 'draft', 'props' => ['id' => 1, 'upper' => 'DRAFT']])
        ->and($schema['records'][0]['cells']['title'])->toBe(['state' => 'Alpha']);
});

it('renders view entries with their component and props', function (): void {
    layoutPosts();
    $post = Post::query()->firstOrFail();

    $entry = Schema::make()->record($post)->components([
        ViewEntry::make('title')->view('TitleCard')->viewData(fn (Post $record): array => ['slug' => $record->slug]),
    ])->toSchema()[0];

    expect($entry)->toMatchArray(['type' => 'ViewEntry', 'name' => 'title', 'state' => 'Alpha', 'component' => 'TitleCard', 'props' => ['slug' => 'alpha']]);
});

it('rejects other values inside a layout', function (): void {
    Table::make()->query(Post::query())->columns([Stack::make(['title'])])->getColumns();
})->throws(InvalidArgumentException::class);

it('rejects form layout components in columns() instead of rendering an empty table', function (): void {
    Table::make()->query(Post::query())->columns([
        AlfaSix\Alfakit\Schemas\Components\Grid::make(['default' => 1, 'lg' => 3])->schema([TextColumn::make('title')]),
    ])->getColumns();
})->throws(InvalidArgumentException::class, 'AlfaSix\Alfakit\Tables\Columns\Layout\Grid');
