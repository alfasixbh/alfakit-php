<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\Summarizers\Average;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Count;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Range;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Sum;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Summarizer;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\TernaryFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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

function summaryState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

it('summarises columns over every matching record', function (): void {
    seedSummaryPosts();

    $schema = Table::make()->query(Post::query())->columns([
        TextColumn::make('title')->summarize(Count::make()),
        TextColumn::make('rating')->summarize([Sum::make(), Average::make()->label('Mean'), Range::make()]),
    ])->state(summaryState())->toSchema();

    expect($schema['summaries'])->not->toHaveKey('page')
        ->and($schema['summaries']['all']['label'])->toBe('All posts')
        ->and($schema['summaries']['all']['values']['title'])->toBe([['label' => 'Count', 'value' => '4']])
        ->and($schema['summaries']['all']['values']['rating'])->toBe([
            ['label' => 'Sum', 'value' => '9'],
            ['label' => 'Mean', 'value' => '3'],
            ['label' => 'Range', 'value' => '1 – 5'],
        ]);
});

it('respects filters and search, and adds a page summary when there are more pages', function (): void {
    seedSummaryPosts();

    $table = Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')->searchable(), TextColumn::make('rating')->summarize(Sum::make())])
        ->filters([TernaryFilter::make('is_published')])
        ->defaultSort('rating', 'desc')
        ->paginationPageOptions([1, 10])
        ->defaultPaginationPageOption(1);

    $schema = $table->state(summaryState(['filters' => ['is_published' => ['value' => '0']]]))->toSchema();

    // Beta (3) and Gamma (1) are unpublished; page 1 shows Beta.
    expect($schema['summaries']['page'])->toBe(['label' => 'This page', 'values' => ['rating' => [['label' => 'Sum', 'value' => '3']]]])
        ->and($schema['summaries']['all']['values']['rating'][0]['value'])->toBe('4');

    $searched = $table->state(summaryState(['search' => 'Gam']))->toSchema();

    expect($searched['summaries']['all']['values']['rating'][0]['value'])->toBe('1')
        ->and($searched['summaries'])->not->toHaveKey('page');
});

it('formats summaries, narrows them with query() and supports custom summarizers', function (): void {
    seedSummaryPosts();

    $schema = Table::make()->query(Post::query())->columns([
        TextColumn::make('rating')->summarize([
            Sum::make()->money('EUR'),
            Average::make()->numeric(1)->query(fn (Builder $query) => $query->where('status', 'draft')),
            Summarizer::make('max')->label('Highest')->using(fn (QueryBuilder $query, string $attribute) => $query->max($attribute))->suffix(' stars'),
            Count::make()->hidden(),
        ]),
        TextColumn::make('published_at')->summarize(Range::make()->date('Y-m-d')),
        TextColumn::make('author.name')->summarize(Sum::make()),
    ])->state(summaryState())->toSchema();

    expect($schema['summaries']['all']['values']['rating'])->toBe([
        ['label' => 'Sum', 'value' => '€9.00'],
        // `numeric(1)` keeps one decimal, like Filament.
        ['label' => 'Average', 'value' => '2.0'],
        ['label' => 'Highest', 'value' => '5 stars'],
    ])
        ->and($schema['summaries']['all']['values']['published_at'][0]['value'])->toBe('2026-01-05 – 2026-02-01')
        // A relationship column has nothing to aggregate without `using()`.
        ->and($schema['summaries']['all']['values']['author.name'][0]['value'])->toBeNull();
});

it('leaves summaries out without summarizers and while records are deferred', function (): void {
    seedSummaryPosts();

    expect(Table::make()->query(Post::query())->columns([TextColumn::make('title')])->state(summaryState())->toSchema())->not->toHaveKey('summaries')
        ->and(Table::make()->query(Post::query())->deferLoading()->columns([TextColumn::make('rating')->summarize(Sum::make())])->state(summaryState())->toSchema())->not->toHaveKey('summaries');
});

it('only summarises visible columns', function (): void {
    seedSummaryPosts();

    $schema = Table::make()->query(Post::query())->columns([
        TextColumn::make('title'),
        TextColumn::make('rating')->toggleable(isToggledHiddenByDefault: true)->summarize(Sum::make()),
    ])->state(summaryState())->toSchema();

    expect($schema)->not->toHaveKey('summaries');
});
