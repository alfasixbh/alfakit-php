<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Infolists\Components\ColorEntry;
use AlfaSix\Alfakit\Infolists\Components\ImageEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tables\Columns\ColorColumn;
use AlfaSix\Alfakit\Tables\Columns\IconColumn;
use AlfaSix\Alfakit\Tables\Columns\ImageColumn;
use AlfaSix\Alfakit\Tables\Columns\Layout\Grid;
use AlfaSix\Alfakit\Tables\Columns\Layout\Stack;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Count;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Sum;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\TextInputColumn;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators\IsRelatedToOperator;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators\Operator;
use AlfaSix\Alfakit\Tables\Grouping\Group;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

function colParityState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

/**
 * @return array<string>
 */
function colParityTitles(Table $table, array $query = []): array
{
    $schema = $table->state(colParityState($query))->toSchema();

    return array_map(static fn (array $record): string => $record['cells']['title']['state'], $schema['records']);
}

// ---------------------------------------------------------------- Copying

it('serialises copyMessage() and copyMessageDuration() on text and color columns and entries', function (): void {
    $text = TextColumn::make('title')->copyable()->copyMessage('Copied title')->copyMessageDuration(3000)->toSchema();
    $color = ColorColumn::make('color')->copyable(message: 'Hex copied', duration: 500)->wrap()->toSchema();

    expect($text)->toMatchArray(['copyable' => true, 'copyMessage' => 'Copied title', 'copyMessageDuration' => 3000])
        ->and($color)->toMatchArray(['copyable' => true, 'copyMessage' => 'Hex copied', 'copyMessageDuration' => 500, 'wrap' => true])
        ->and(ColorColumn::make('color')->toSchema())->not->toHaveKey('copyable')->not->toHaveKey('copyMessageDuration');

    $schema = Schema::make()->components([
        TextEntry::make('title')->copyable()->copyMessageDuration(fn (): int => 2000),
        ColorEntry::make('color')->copyable()->copyMessageDuration(800),
    ])->fill(['title' => 'Hello', 'color' => '#ff0000'])->toSchema();

    expect($schema[0]['copyMessageDuration'])->toBe(2000)
        ->and($schema[1]['copyMessageDuration'])->toBe(800);
});

// ---------------------------------------------------------------- Formatting

it('applies a standalone timezone() before or after date()', function (): void {
    $after = TextColumn::make('at')->dateTime('Y-m-d H:i')->timezone('Asia/Dubai');
    $before = TextColumn::make('at')->timezone(fn (): string => 'Asia/Tokyo')->dateTime('Y-m-d H:i');
    $argument = TextColumn::make('at')->timezone('Asia/Tokyo')->dateTime('Y-m-d H:i', 'UTC');

    expect($after->toCell(['at' => '2026-01-01 10:00:00'])['state'])->toBe('2026-01-01 14:00')
        ->and($before->toCell(['at' => '2026-01-01 10:00:00'])['state'])->toBe('2026-01-01 19:00')
        ->and($argument->toCell(['at' => '2026-01-01 10:00:00'])['state'])->toBe('2026-01-01 10:00');

    $entry = Schema::make()->components([
        TextEntry::make('at')->since()->timezone('Asia/Dubai'),
        TextEntry::make('at')->isoDate('YYYY-MM-DD HH:mm')->timezone('Asia/Dubai'),
    ])->fill(['at' => '2026-01-01 10:00:00'])->toSchema();

    expect($entry[1]['state'])->toBe('2026-01-01 14:00');
});

it('formats numbers with separators and decimal limits', function (): void {
    $cell = static fn (TextColumn $column, mixed $value): mixed => $column->toCell(['n' => $value])['state'];

    expect($cell(TextColumn::make('n')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: '.'), 1234567.891))->toBe('1.234.567,89')
        ->and($cell(TextColumn::make('n')->numeric(thousandsSeparator: ' '), 1234567))->toBe('1 234 567')
        ->and($cell(TextColumn::make('n')->numeric(maxDecimalPlaces: 2), 3.10))->toBe('3.1')
        ->and($cell(TextColumn::make('n')->numeric(maxDecimalPlaces: 2), 3.14159))->toBe('3.14')
        ->and($cell(TextColumn::make('n')->numeric(decimalSeparator: ',', maxDecimalPlaces: 2), 1000.5))->toBe('1,000,5')
        ->and($cell(TextColumn::make('n')->numeric(2), 1234.5))->toBe('1,234.50')
        // AlfaKit 1.x order: a locale as the second argument.
        ->and($cell(TextColumn::make('n')->numeric(2, 'de'), 1234.5))->toBe('1.234,50')
        ->and($cell(TextColumn::make('n')->numeric(locale: 'de'), 1234.5))->toBe('1.234,5')
        ->and($cell(TextColumn::make('n')->numeric(decimalPlaces: 0, thousandsSeparator: "'"), 'not a number'))->toBe('not a number');
});

it('formats money with fixed decimal places', function (): void {
    $cell = static fn (TextColumn $column, mixed $value): mixed => $column->toCell(['n' => $value])['state'];

    expect($cell(TextColumn::make('n')->money('USD', decimalPlaces: 0), 1234.56))->toBe('$1,235')
        ->and($cell(TextColumn::make('n')->money('USD', 100, null, 0), 123456))->toBe('$1,235')
        ->and($cell(TextColumn::make('n')->money('USD', inMinorUnits: true, decimalPlaces: 1), 123456))->toBe('$1,234.6')
        ->and($cell(TextColumn::make('n')->money('USD'), 1234.5))->toBe('$1,234.50');

    $entry = Schema::make()->components([TextEntry::make('n')->money('EUR', locale: 'en', decimalPlaces: fn (): int => 0)])->fill(['n' => 9.99])->toSchema();

    expect($entry[0]['state'])->toBe('€10');
});

// ---------------------------------------------------------------- Icon, image, select, text input columns

it('renders one icon per item of a list state and wraps them', function (): void {
    $column = IconColumn::make('flags')
        ->icon(fn (string $state): string => $state === 'hot' ? 'flame' : 'snowflake')
        ->color(fn (string $state): ?string => $state === 'hot' ? 'danger' : null)
        ->wrap();

    expect($column->toSchema()['wrap'])->toBeTrue()
        ->and($column->toCell(['flags' => ['hot', 'cold', '']])['icons'])->toBe([
            ['icon' => 'flame', 'color' => 'danger'],
            ['icon' => 'snowflake', 'color' => null],
        ])
        ->and($column->toCell(['flags' => 'hot']))->toMatchArray(['icon' => 'flame', 'color' => 'danger'])->not->toHaveKey('icons');

    $boolean = IconColumn::make('checks')->boolean();

    expect($boolean->toCell(['checks' => [true, false]])['icons'])->toBe([
        ['icon' => 'circle-check', 'color' => 'success'],
        ['icon' => 'circle-x', 'color' => 'danger'],
    ])
        // Icons named by the state itself.
        ->and(IconColumn::make('names')->toCell(['names' => collect(['star', 'heart'])])['icons'])->toHaveCount(2)
        ->and(IconColumn::make('flags')->toSchema())->not->toHaveKey('wrap');
});

it('serialises image limitedRemainingText(size:) and wrap()', function (): void {
    $schema = ImageColumn::make('images')->limit(2)->limitedRemainingText(size: 'large')->wrap()->toSchema();

    expect($schema)->toMatchArray(['limitedRemainingText' => true, 'limitedRemainingTextSize' => 'lg', 'wrap' => true])
        ->and(ImageColumn::make('a')->limitedRemainingText(size: 'xs')->toSchema()['limitedRemainingTextSize'])->toBe('xs')
        ->and(ImageColumn::make('a')->limitedRemainingText(size: 'huge')->toSchema())->not->toHaveKey('limitedRemainingTextSize')
        ->and(ImageColumn::textSize(AlfaSix\Alfakit\Support\Enums\Size::Medium))->toBe('md');
});

it('supports limitedRemainingText() and checkFileExistence() on image entries', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('real.png', 'x');

    $schema = Schema::make()->components([
        ImageEntry::make('images')->disk('public')->checkFileExistence()->limit(1)->limitedRemainingText(false),
        ImageEntry::make('images')->disk('public')->limitedRemainingText(size: 'md'),
        ImageEntry::make('images')->disk('public')->checkFileExistence(false),
    ])->fill(['images' => ['real.png', 'missing.png']])->toSchema();

    expect($schema[0]['state'])->toHaveCount(1)
        ->and($schema[0]['limitedRemainingText'])->toBeFalse()
        ->and($schema[1])->toMatchArray(['limitedRemainingText' => true, 'limitedRemainingTextSize' => 'md'])
        ->and($schema[2]['state'])->toHaveCount(2)
        ->and($schema[2])->not->toHaveKey('limitedRemainingText');
});

it('serialises select column search debounce and option label wrapping', function (): void {
    $combobox = SelectColumn::make('status')->options(['a' => 'A'])->native(false)->optionsSearchDebounce(500)->wrapOptionLabels(false)->rememberOptions(false);

    expect($combobox->toSchema())->toMatchArray(['optionsSearchDebounce' => 500, 'wrapOptionLabels' => false])
        ->and($combobox->shouldRememberOptions())->toBeFalse()
        ->and(SelectColumn::make('status')->options(['a' => 'A'])->native(false)->toSchema())->not->toHaveKey('wrapOptionLabels')->not->toHaveKey('optionsSearchDebounce')
        // The browser select has neither.
        ->and(SelectColumn::make('status')->options(['a' => 'A'])->optionsSearchDebounce(500)->toSchema())->not->toHaveKey('optionsSearchDebounce');
});

it('sends text input column prefix and suffix per record', function (): void {
    $column = TextInputColumn::make('price')->prefix(fn (array $record): string => $record['currency'])->suffix('/ month');

    expect($column->toCell(['price' => 10, 'currency' => '€']))->toMatchArray(['prefix' => '€', 'suffix' => '/ month'])
        ->and(TextInputColumn::make('price')->toCell(['price' => 1]))->not->toHaveKey('prefix')->not->toHaveKey('suffix');
});

// ---------------------------------------------------------------- Layout

it('serialises columnSpan() and columnStart() of columns and layouts inside a grid', function (): void {
    seedSummaryPosts();

    $schema = Table::make()->query(Post::query())->columns([
        Grid::make(['default' => 1, 'lg' => 3])->schema([
            TextColumn::make('title')->columnSpan(2),
            TextColumn::make('status')->columnSpanFull(),
            Stack::make([TextColumn::make('rating')])->columnSpan(['md' => 2])->columnStart(['md' => 2]),
            TextColumn::make('slug'),
        ]),
    ])->state(colParityState())->toSchema();

    $grid = $schema['contentLayout'][0]['schema'];

    expect($grid[0])->toBe(['type' => 'Column', 'name' => 'title', 'columnSpan' => ['lg' => 2]])
        ->and($grid[1]['columnSpan'])->toBe(['default' => 'full'])
        ->and($grid[2])->toMatchArray(['type' => 'Stack', 'columnSpan' => ['md' => 2], 'columnStart' => ['md' => 2]])
        ->and($grid[3])->toBe(['type' => 'Column', 'name' => 'slug']);
});

// ---------------------------------------------------------------- Summaries

it('counts icon occurrences with Count::icons()', function (): void {
    seedSummaryPosts();

    $schema = Table::make()->query(Post::query())->columns([
        TextColumn::make('title'),
        IconColumn::make('is_published')->boolean()->summarize(Count::make()->icons()),
        IconColumn::make('status')->icon(fn (string $state): string => $state === 'draft' ? 'pencil' : 'globe')->summarize(Count::make()->icons()->hiddenLabel()),
        TextColumn::make('rating')->summarize(Count::make()->icons()),
    ])->state(colParityState())->toSchema();

    $values = $schema['summaries']['all']['values'];

    expect($values['is_published'][0]['icons'])->toEqualCanonicalizing([
        ['icon' => 'circle-check', 'color' => 'success', 'count' => 2],
        ['icon' => 'circle-x', 'color' => 'danger', 'count' => 2],
    ])
        ->and($values['is_published'][0]['value'])->toBeNull()
        ->and($values['status'][0])->toMatchArray(['label' => 'Count', 'hiddenLabel' => true])
        ->and(array_column($values['status'][0]['icons'], 'count', 'icon'))->toEqual(['pencil' => 2, 'globe' => 2])
        // `icons()` only applies to icon columns.
        ->and($values['rating'][0])->toBe(['label' => 'Count', 'value' => '4']);
});

it('hides summarizer labels visually and summary rows with summaries()', function (): void {
    seedSummaryPosts();

    $table = fn (): Table => Table::make()->query(Post::query())
        ->columns([TextColumn::make('title'), TextColumn::make('rating')->summarize(Sum::make()->hiddenLabel(fn (): bool => true))])
        ->defaultSort('title')
        ->paginationPageOptions([1, 10])
        ->defaultPaginationPageOption(1);

    $both = $table()->state(colParityState())->toSchema()['summaries'];

    expect($both)->toHaveKeys(['page', 'all'])
        ->and($both['all']['values']['rating'][0])->toBe(['label' => 'Sum', 'hiddenLabel' => true, 'value' => '9']);

    expect($table()->summaries(pageCondition: false)->state(colParityState())->toSchema()['summaries'])->toHaveKey('all')->not->toHaveKey('page')
        ->and($table()->summaries(allTableCondition: fn (): bool => false)->state(colParityState())->toSchema()['summaries'])->toHaveKey('page')->not->toHaveKey('all')
        ->and($table()->summaries(pageCondition: false, allTableCondition: false)->state(colParityState())->toSchema())->not->toHaveKey('summaries');
});

// ---------------------------------------------------------------- Grouping and sorting

it('applies the default group direction until the user picks another', function (): void {
    seedSummaryPosts();

    $table = fn (): Table => Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')])
        ->groups([Group::make('status'), Group::make('author.name')])
        ->defaultGroup('status', direction: 'desc')
        ->defaultSort('title');

    $schema = $table()->state(colParityState())->toSchema();

    expect($schema['grouping']['direction'])->toBe('desc')
        ->and(array_column($schema['records'], 'group'))->toBe(['published', 'published', 'draft', 'draft'])
        ->and($table()->state(colParityState(['groupDirection' => 'asc']))->toSchema()['grouping']['direction'])->toBe('asc')
        // Another group starts ascending.
        ->and($table()->state(colParityState(['group' => 'author.name']))->toSchema()['grouping']['direction'])->toBe('asc')
        ->and($table()->groupingDirectionSettingHidden()->state(colParityState(['groupDirection' => 'asc']))->toSchema()['grouping']['direction'])->toBe('desc');
});

it('lets defaultSort() take a closure that orders the query (regression)', function (): void {
    seedSummaryPosts();

    $table = fn (Closure $sort, string $direction = 'asc'): Table => Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')->sortable(), TextColumn::make('rating')])
        ->defaultSort($sort, $direction);

    $byQuery = $table(fn (Builder $query): Builder => $query->orderBy('status')->orderByDesc('title'));

    expect(colParityTitles($byQuery))->toBe(['Gamma', 'Beta', 'Delta', 'Alpha'])
        ->and($byQuery->state(colParityState())->toSchema())->not->toHaveKey('sort')
        // `$direction` is injected.
        ->and(colParityTitles($table(fn ($query, string $direction) => $query->orderBy('title', $direction), 'desc')))->toBe(['Gamma', 'Delta', 'Beta', 'Alpha'])
        // A closure returning a column name still works.
        ->and(colParityTitles($table(fn (): string => 'title', 'desc')))->toBe(['Gamma', 'Delta', 'Beta', 'Alpha'])
        // A requested sort wins over the query closure.
        ->and(colParityTitles($byQuery, ['sort' => 'title', 'direction' => 'asc']))->toBe(['Alpha', 'Beta', 'Delta', 'Gamma']);
});

// ---------------------------------------------------------------- Query builder

function colParityQbTable(array $constraints): Table
{
    return Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')])
        ->defaultSort('title')
        ->filters([QueryBuilder::make()->constraints($constraints)]);
}

function colParityRules(array $rules): array
{
    return ['filters' => ['queryBuilder' => ['query' => ['combinator' => 'and', 'rules' => $rules]]]];
}

function colParityRule(string $constraint, string $operator, mixed $value = null): array
{
    return array_filter(['type' => 'constraint', 'constraint' => $constraint, 'operator' => $operator, 'value' => $value], static fn (mixed $item): bool => $item !== null);
}

it('filters dates by month and year, and by date and time with time()', function (): void {
    seedSummaryPosts();

    $table = fn (): Table => colParityQbTable([DateConstraint::make('published_at'), DateConstraint::make('published')->attribute('published_at')->time()]);
    $all = ['Alpha', 'Beta', 'Delta', 'Gamma'];

    expect(colParityTitles($table(), colParityRules([colParityRule('published_at', 'is_month', '2026-01')])))->toBe(['Alpha', 'Beta'])
        ->and(colParityTitles($table(), colParityRules([colParityRule('published_at', 'is_year', '2026')])))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and(colParityTitles($table(), colParityRules([colParityRule('published_at', 'is_year', '2025')])))->toBe([])
        ->and(colParityTitles($table(), colParityRules([colParityRule('published', 'is_after', '2026-01-05T12:00')])))->toBe(['Beta', 'Gamma'])
        ->and(colParityTitles($table(), colParityRules([colParityRule('published', 'is_before', '2026-01-05 12:00:00')])))->toBe(['Alpha'])
        ->and(colParityTitles($table(), colParityRules([colParityRule('published', 'is_date', '2026-01-05')])))->toBe(['Alpha', 'Beta'])
        // Invalid values are ignored.
        ->and(colParityTitles($table(), colParityRules([colParityRule('published_at', 'is_month', '2026-13')])))->toBe($all)
        ->and(colParityTitles($table(), colParityRules([colParityRule('published_at', 'is_year', 'abc')])))->toBe($all)
        ->and(colParityTitles($table(), colParityRules([colParityRule('published', 'is_after', '2026-01-05')])))->toBe($all);

    $operators = collect($table()->state(colParityState())->toSchema()['filters'][0]['schema'][0]['constraints'])->keyBy('name');

    expect(collect($operators['published_at']['operators'])->pluck('input', 'name')->all())->toMatchArray(['is_after' => 'date', 'is_month' => 'month', 'is_year' => 'year'])
        ->and(collect($operators['published']['operators'])->pluck('input', 'name')->all())->toMatchArray(['is_after' => 'datetime', 'is_before' => 'datetime', 'is_date' => 'date']);
});

it('removes the null operators with nullable(false)', function (): void {
    $constraint = TextConstraint::make('title')->nullable(false);

    expect($constraint->getAvailableOperators())->not->toHaveKeys(['is_filled', 'is_blank'])
        ->and($constraint->resolveRule('is_blank', null))->toBeNull()
        ->and(TextConstraint::make('title')->nullable()->getAvailableOperators())->toHaveKeys(['is_filled', 'is_blank'])
        ->and(TextConstraint::make('title')->getAvailableOperators())->toHaveKeys(['is_filled', 'is_blank']);
});

it('serialises searchable select and relationship constraints, and IsRelatedToOperator settings', function (): void {
    $select = SelectConstraint::make('status')->options(['draft' => 'Draft'])->searchable()->toSchema();
    $relationship = RelationshipConstraint::make('tags')->selectable(
        IsRelatedToOperator::make()->titleAttribute('name')->searchable()->multiple()->optionsLimit(5),
    );

    expect($select['searchable'])->toBeTrue()
        ->and(SelectConstraint::make('status')->options(['a' => 'A'])->toSchema())->not->toHaveKey('searchable')
        ->and($relationship->isSearchable())->toBeTrue()
        ->and($relationship->isMultiple())->toBeTrue()
        ->and($relationship->toSchema())->toMatchArray(['searchable' => true, 'multiple' => true]);
});

it('limits IsRelatedToOperator options to the modified, tenant-scoped query', function (): void {
    seedSummaryPosts();
    Tag::query()->create(['name' => 'News']);
    Tag::query()->create(['name' => 'Art']);

    $constraint = RelationshipConstraint::make('tags')
        ->selectable(IsRelatedToOperator::make()->titleAttribute('name')->modifyQueryUsing(fn (Builder $query) => $query->where('name', 'Art')))
        ->model(new Post);

    expect(array_values($constraint->getOptions()))->toBe(['Art']);
});

it('runs custom operators that take a validated value', function (): void {
    seedSummaryPosts();

    $table = fn (): Table => colParityQbTable([
        TextConstraint::make('title')->pushOperators([
            Operator::make('longer_than')->label('is longer than')->input('number')
                ->query(fn (Builder $query, string $column, int|float $value) => $query->whereRaw('length('.$column.') > ?', [$value])),
            Operator::make('status_is')->label('has status')->input('select')->options(['draft' => 'Draft', 'published' => 'Published'])
                ->query(fn (Builder $query, mixed $value) => $query->where('status', $value)),
            Operator::make('short')->query(fn (Builder $query, string $column) => $query->whereRaw('length('.$column.') <= 4')),
        ]),
    ]);
    $all = ['Alpha', 'Beta', 'Delta', 'Gamma'];

    expect(colParityTitles($table(), colParityRules([colParityRule('title', 'longer_than', '4')])))->toBe(['Alpha', 'Delta', 'Gamma'])
        ->and(colParityTitles($table(), colParityRules([colParityRule('title', 'status_is', 'draft')])))->toBe(['Beta', 'Gamma'])
        ->and(colParityTitles($table(), colParityRules([colParityRule('title', 'short')])))->toBe(['Beta'])
        // Values that do not fit the input are ignored.
        ->and(colParityTitles($table(), colParityRules([colParityRule('title', 'longer_than', 'abc')])))->toBe($all)
        ->and(colParityTitles($table(), colParityRules([colParityRule('title', 'status_is', 'secret')])))->toBe($all);

    $schema = $table()->state(colParityState(colParityRules([colParityRule('title', 'status_is', 'draft')])))->toSchema();
    $operators = collect($schema['filters'][0]['schema'][0]['constraints'][0]['operators'])->keyBy('name');

    expect($operators['longer_than'])->toBe(['name' => 'longer_than', 'label' => 'is longer than', 'input' => 'number'])
        ->and($operators['status_is']['options'])->toBe([['value' => 'draft', 'label' => 'Draft'], ['value' => 'published', 'label' => 'Published']])
        ->and($operators['short']['input'])->toBe('none')
        ->and($schema['filterIndicators'][0]['label'])->toContain('has status')->toContain('Draft');
});

it('validates custom operator values with validateValueUsing()', function (): void {
    $operator = Operator::make('even')->input('number')->validateValueUsing(fn (int|float $value): ?int => $value % 2 === 0 ? (int) $value : null);

    expect($operator->validateValue('4'))->toBe(4)
        ->and($operator->validateValue('3'))->toBeNull()
        ->and(Operator::make('m')->input('month')->validateValue('2026-02'))->toBe('2026-02')
        ->and(Operator::make('m')->input('month')->validateValue('2026-2'))->toBeNull()
        ->and(Operator::make('d')->input('datetime')->validateValue('2026-02-01 10:30:00'))->toBe('2026-02-01T10:30')
        ->and(Operator::make('x')->input('unknown')->takesValue())->toBeFalse();
});
