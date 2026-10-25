<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\DatePicker;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

function filterFormDataTable(): Table
{
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    Post::query()->create(['title' => 'Old', 'slug' => 'old', 'author_id' => $ada->id, 'published_at' => '2026-01-05 10:00:00']);
    Post::query()->create(['title' => 'New', 'slug' => 'new', 'author_id' => $ada->id, 'published_at' => '2026-03-01 10:00:00']);

    // The docs' (and Filament's) date range filter: it reads both keys of $data without isset().
    return Table::make()->query(Post::query())->defaultSort('title')->columns([TextColumn::make('title')])->filters([
        Filter::make('created')
            ->form([Grid::make(2)->schema([DatePicker::make('from'), DatePicker::make('until')])])
            ->query(fn (Builder $query, array $data) => $query
                ->when($data['from'], fn ($q, $d) => $q->whereDate('published_at', '>=', $d))
                ->when($data['until'], fn ($q, $d) => $q->whereDate('published_at', '<=', $d)))
            ->indicateUsing(fn (array $data) => array_filter([$data['from'] ? "From {$data['from']}" : null, $data['until'] ? "Until {$data['until']}" : null])),
    ]);
}

it('passes every field of a custom filter form to query() and indicateUsing(), null when not filled', function (): void {
    $table = filterFormDataTable();
    $table->state(TableState::fromRequest(Request::create('/', 'GET', ['filters' => ['created' => ['from' => '2026-02-01']]])));

    expect($table->getRecords()->pluck('title')->all())->toBe(['New'])
        ->and($table->getFilters()['created']->getState())->toBe(['from' => '2026-02-01', 'until' => null])
        ->and(array_column($table->toSchema()['filterIndicators'], 'label'))->toBe(['From 2026-02-01']);
});

it('keeps a custom filter form inactive while none of its fields is filled', function (): void {
    $table = filterFormDataTable();
    $table->state(TableState::fromRequest(Request::create('/', 'GET')));

    expect($table->getFilters()['created']->isActive())->toBeFalse()
        ->and($table->getRecords()->pluck('title')->all())->toBe(['New', 'Old']);
});
