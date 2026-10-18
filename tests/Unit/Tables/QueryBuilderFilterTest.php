<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
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

function queryBuilderTable(int $maxDepth = 2): Table
{
    return Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')])
        ->defaultSort('title')
        ->filters([
            QueryBuilder::make()->maxDepth($maxDepth)->constraints([
                TextConstraint::make('title'),
                NumberConstraint::make('rating')->integer(),
                DateConstraint::make('published_at'),
                BooleanConstraint::make('is_published'),
                SelectConstraint::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->multiple(),
                RelationshipConstraint::make('tags')->titleAttribute('name')->multiple(),
                TextConstraint::make('author.name')->label('Author'),
            ]),
        ]);
}

/**
 * @param  array<int, array<string, mixed>>  $rules
 * @return array<string>
 */
function queryBuilderTitles(array $rules, string $combinator = 'and', int $maxDepth = 2): array
{
    $state = TableState::fromRequest(Request::create('/', 'GET', ['filters' => ['queryBuilder' => ['query' => ['combinator' => $combinator, 'rules' => $rules]]]]));
    $schema = queryBuilderTable($maxDepth)->state($state)->toSchema();

    return array_map(static fn (array $record): string => $record['cells']['title']['state'], $schema['records']);
}

function qbRule(string $constraint, string $operator, mixed $value = null): array
{
    return array_filter(['type' => 'constraint', 'constraint' => $constraint, 'operator' => $operator, 'value' => $value], static fn (mixed $item): bool => $item !== null);
}

beforeEach(function (): void {
    seedSummaryPosts();

    $news = Tag::query()->create(['name' => 'News']);
    $art = Tag::query()->create(['name' => 'Art']);
    Post::query()->where('title', 'Alpha')->first()?->tags()->attach([$news->id, $art->id]);
    Post::query()->where('title', 'Beta')->first()?->tags()->attach([$art->id]);
});

it('applies text, number, date, boolean and select rules', function (): void {
    expect(queryBuilderTitles([qbRule('title', 'contains', 'MM')]))->toBe(['Gamma'])
        ->and(queryBuilderTitles([qbRule('title', 'not_contains', 'a')]))->toBe([])
        ->and(queryBuilderTitles([qbRule('title', 'starts_with', 'b')]))->toBe(['Beta'])
        ->and(queryBuilderTitles([qbRule('title', 'equals', 'delta')]))->toBe(['Delta'])
        ->and(queryBuilderTitles([qbRule('rating', 'gte', '3')]))->toBe(['Alpha', 'Beta'])
        ->and(queryBuilderTitles([qbRule('rating', 'not_equals', '3')]))->toBe(['Alpha', 'Delta', 'Gamma'])
        ->and(queryBuilderTitles([qbRule('rating', 'is_blank')]))->toBe(['Delta'])
        ->and(queryBuilderTitles([qbRule('published_at', 'is_date', '2026-01-05')]))->toBe(['Alpha', 'Beta'])
        ->and(queryBuilderTitles([qbRule('published_at', 'is_after', '2026-01-05')]))->toBe(['Gamma'])
        ->and(queryBuilderTitles([qbRule('is_published', 'is_true')]))->toBe(['Alpha', 'Delta'])
        ->and(queryBuilderTitles([qbRule('status', 'is', ['draft'])]))->toBe(['Beta', 'Gamma'])
        ->and(queryBuilderTitles([qbRule('status', 'is_not', ['draft', 'published'])]))->toBe([]);
});

it('applies relationship rules and attributes on relationships', function (): void {
    $art = (string) Tag::query()->where('name', 'Art')->value('id');

    expect(queryBuilderTitles([qbRule('tags', 'has', [$art])]))->toBe(['Alpha', 'Beta'])
        ->and(queryBuilderTitles([qbRule('tags', 'has_min', '2')]))->toBe(['Alpha'])
        ->and(queryBuilderTitles([qbRule('tags', 'has_max', '1')]))->toBe(['Beta', 'Delta', 'Gamma'])
        ->and(queryBuilderTitles([qbRule('tags', 'is_empty')]))->toBe(['Delta', 'Gamma'])
        ->and(queryBuilderTitles([qbRule('author.name', 'equals', 'bob')]))->toBe(['Beta'])
        ->and(queryBuilderTitles([qbRule('author.name', 'is_blank')]))->toBe(['Delta']);
});

it('combines rules with and / or and nested groups', function (): void {
    expect(queryBuilderTitles([qbRule('status', 'is', ['draft']), qbRule('rating', 'lt', '2')]))->toBe(['Gamma'])
        ->and(queryBuilderTitles([qbRule('title', 'equals', 'alpha'), qbRule('title', 'equals', 'beta')], 'or'))->toBe(['Alpha', 'Beta'])
        ->and(queryBuilderTitles([
            qbRule('is_published', 'is_true'),
            ['type' => 'group', 'combinator' => 'or', 'rules' => [qbRule('rating', 'gt', '4'), qbRule('rating', 'is_blank')]],
        ]))->toBe(['Alpha', 'Delta'])
        ->and(queryBuilderTitles([
            qbRule('title', 'equals', 'gamma'),
            ['type' => 'group', 'combinator' => 'and', 'rules' => [qbRule('status', 'is', ['draft']), qbRule('rating', 'equals', '3')]],
        ], 'or'))->toBe(['Beta', 'Gamma']);
});

it('ignores unknown constraints, operators, invalid values and groups beyond the depth limit', function (): void {
    $all = ['Alpha', 'Beta', 'Delta', 'Gamma'];

    expect(queryBuilderTitles([qbRule('password', 'equals', 'x')]))->toBe($all)
        ->and(queryBuilderTitles([qbRule('title', 'drop table', 'x')]))->toBe($all)
        ->and(queryBuilderTitles([qbRule('rating', 'gt', 'abc')]))->toBe($all)
        ->and(queryBuilderTitles([qbRule('rating', 'gt', ['1'])]))->toBe($all)
        ->and(queryBuilderTitles([qbRule('published_at', 'is_date', '2026-13-45')]))->toBe($all)
        ->and(queryBuilderTitles([qbRule('status', 'is', ['secret'])]))->toBe($all)
        ->and(queryBuilderTitles([qbRule('title', 'contains', str_repeat('a', 300))]))->toBe($all)
        ->and(queryBuilderTitles(['not a rule', ['type' => 'group', 'rules' => 'nope']]))->toBe($all)
        // Depth 0 allows no groups at all.
        ->and(queryBuilderTitles([['type' => 'group', 'combinator' => 'and', 'rules' => [qbRule('title', 'equals', 'alpha')]]], 'and', 0))->toBe($all);
});

it('serialises constraints, operators and options, and shows one indicator per rule', function (): void {
    $state = TableState::fromRequest(Request::create('/', 'GET', ['filters' => ['queryBuilder' => ['query' => ['rules' => [
        qbRule('title', 'contains', 'a'),
        qbRule('status', 'is', ['draft', 'published']),
        qbRule('is_published', 'is_false'),
    ]]]]]));
    $schema = queryBuilderTable()->state($state)->toSchema();
    $field = $schema['filters'][0]['schema'][0];
    $constraints = collect($field['constraints'])->keyBy('name');

    expect($schema['filters'][0]['type'])->toBe('QueryBuilder')
        ->and($field['type'])->toBe('QueryBuilderRules')
        ->and($field['statePath'])->toBe('queryBuilder.query')
        ->and($field['maxDepth'])->toBe(2)
        ->and($constraints['title']['operators'][0])->toBe(['name' => 'contains', 'label' => 'contains', 'input' => 'text'])
        ->and(collect($constraints['title']['operators'])->firstWhere('name', 'is_blank')['input'])->toBe('none')
        ->and($constraints['rating']['step'])->toBe(1)
        ->and($constraints['status']['multiple'])->toBeTrue()
        ->and(array_column($constraints['tags']['options'], 'label'))->toBe(['Art', 'News'])
        ->and(collect($constraints['tags']['operators'])->pluck('input', 'name')->all())->toBe(['has' => 'select', 'has_min' => 'number', 'has_max' => 'number', 'is_empty' => 'none', 'is_not_empty' => 'none'])
        ->and(array_column($schema['filterIndicators'], 'label'))->toBe([
            'Title contains “a”',
            'Status is “Draft, Published”',
            'Is published is false',
        ]);
});
