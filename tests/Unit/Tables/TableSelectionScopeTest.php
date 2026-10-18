<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Http\Request;

function scopedTable(array $query = []): Table
{
    $request = Request::create('/', 'GET', $query);
    app()->instance('request', $request);

    return Table::make()
        ->query(Post::query())
        ->columns([TextColumn::make('title')->searchable()])
        ->filters([SelectFilter::make('is_published')->options(['1' => 'Yes', '0' => 'No'])])
        ->bulkActions([BulkAction::make('archive')])
        ->state(TableState::fromRequest($request));
}

it('changes the selection scope when filters, search or the list tab change', function (): void {
    $base = scopedTable()->toSchema()['selectionScope'];

    expect($base)->toBeString()
        ->and(scopedTable(['page' => 2, 'sort' => 'title'])->toSchema()['selectionScope'])->toBe($base)
        ->and(scopedTable(['search' => 'hello'])->toSchema()['selectionScope'])->not->toBe($base)
        ->and(scopedTable(['filters' => ['is_published' => ['value' => '1']]])->toSchema()['selectionScope'])->not->toBe($base)
        ->and(scopedTable(['activeTab' => 'drafts'])->toSchema()['selectionScope'])->not->toBe($base);
});

it('keeps the selection with deselectAllRecordsWhenFiltered(false)', function (): void {
    expect(scopedTable()->deselectAllRecordsWhenFiltered(false)->toSchema())->not->toHaveKey('selectionScope');
});
