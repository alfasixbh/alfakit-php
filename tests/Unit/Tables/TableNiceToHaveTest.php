<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\BulkActionGroup;
use AlfaSix\Alfakit\Forms\Components\Checkbox;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Infolists\Components\ImageEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\VerticalAlignment;
use AlfaSix\Alfakit\Tables\Columns\ImageColumn;
use AlfaSix\Alfakit\Tables\Columns\Summarizers\Range;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Columns\TextInputColumn;
use AlfaSix\Alfakit\Tables\Enums\ColumnManagerLayout;
use AlfaSix\Alfakit\Tables\Enums\ColumnManagerResetActionPosition;
use AlfaSix\Alfakit\Tables\Enums\FiltersResetActionPosition;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\Indicator;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Operators\Operator;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Grouping\Group;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Category;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

function niceState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

function nicePosts(): void
{
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $news = Category::query()->create(['name' => 'News', 'slug' => 'news']);

    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha', 'author_id' => $ada->id, 'category_id' => $news->id, 'rating' => 3, 'status' => 'draft', 'published_at' => '2026-01-05 10:00:00']);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta', 'author_id' => $ada->id, 'rating' => 3, 'status' => 'draft', 'published_at' => '2026-01-05 18:00:00']);
    Post::query()->create(['title' => 'Gamma', 'slug' => 'gamma', 'author_id' => $ada->id, 'category_id' => $news->id, 'rating' => 1, 'status' => 'published', 'published_at' => '2026-02-01 09:00:00']);
}

function niceTitles(Table $table, array $query = []): array
{
    return $table->state(niceState($query))->getRecords()->pluck('title')->all();
}

// ---------------------------------------------------------------- Pagination & layout

it('adds pushed columns and filters after the configured ones', function (): void {
    nicePosts();

    $table = Table::make()->query(Post::query())
        ->pushColumns([TextColumn::make('created_at')])
        ->pushFilters([Filter::make('top')->query(fn (Builder $q) => $q->where('rating', '>=', 3))])
        ->columns([TextColumn::make('title')->sortable()])
        ->filters([SelectFilter::make('status')->options(['draft' => 'Draft'])]);

    expect(array_keys($table->getColumns()))->toBe(['title', 'created_at'])
        ->and(array_keys($table->getFilters()))->toBe(['status', 'top'])
        ->and(niceTitles($table, ['filters' => ['top' => ['isActive' => '1']], 'sort' => 'title']))->toBe(['Alpha', 'Beta']);
});

it('serialises toolbar options only when they differ from the defaults', function (): void {
    nicePosts();

    $plain = Table::make()->query(Post::query())->columns([TextColumn::make('title')->toggleable()])->state(niceState())->toSchema();

    expect($plain)->not->toHaveKeys(['extremePaginationLinks', 'loadingSkeleton', 'deferColumnManager', 'columnManagerLayout', 'columnManagerColumns', 'filtersResetActionPosition', 'groupingSettingsInDropdownOnDesktop', 'triggerActions', 'defaultSortOptionLabel']);

    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')->toggleable()])
        ->extremePaginationLinks()
        ->loadingSkeleton(fn (): bool => true)
        ->deferColumnManager()
        ->columnManagerLayout(ColumnManagerLayout::Modal)
        ->columnManagerResetActionPosition(ColumnManagerResetActionPosition::Header)
        ->columnManagerColumns(2)
        ->filtersResetActionPosition(FiltersResetActionPosition::Header)
        ->groupingSettingsInDropdownOnDesktop()
        ->defaultSortOptionLabel('Newest')
        ->state(niceState())->toSchema();

    expect($schema)->toMatchArray([
        'extremePaginationLinks' => true,
        'loadingSkeleton' => true,
        'deferColumnManager' => true,
        'columnManagerLayout' => 'modal',
        'columnManagerResetActionPosition' => 'header',
        'columnManagerColumns' => ['default' => 2],
        'filtersResetActionPosition' => 'header',
        'groupingSettingsInDropdownOnDesktop' => true,
        'defaultSortOptionLabel' => 'Newest',
    ]);
});

it('leaves column manager options out when there is no column manager', function (): void {
    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')])->deferColumnManager()->columnManagerColumns(3)->state(niceState())->toSchema();

    expect($schema)->not->toHaveKeys(['deferColumnManager', 'columnManagerColumns']);
});

it('customises trigger actions with closures receiving an action', function (): void {
    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')])
        ->filtersTriggerAction(fn (Action $action) => $action->button()->label('Filter')->icon('funnel')->badge(3))
        ->filtersApplyAction(fn (Action $action) => $action->link()->label('Save filters'))
        ->filtersResetAction(fn (Action $action) => $action->tooltip('Clear'))
        ->filtersRemoveAllAction(fn (Action $action) => $action->hidden())
        ->columnManagerTriggerAction(fn (Action $action) => $action->iconButton())
        ->groupRecordsTriggerAction(fn ($action) => $action->label('Group records')->color('primary'))
        ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering) => $action->label($isReordering ? 'Done' : 'Sort rows'))
        ->state(niceState())->toSchema();

    expect($schema['triggerActions']['filters'])->toMatchArray(['label' => 'Filter', 'icon' => 'funnel', 'badge' => 3, 'variant' => 'button'])
        ->and($schema['triggerActions']['filters'])->not->toHaveKeys(['name', 'hasAction', 'url'])
        ->and($schema['triggerActions']['filtersApply'])->toMatchArray(['label' => 'Save filters', 'variant' => 'link'])
        ->and($schema['triggerActions']['filtersReset']['tooltip'])->toBe('Clear')
        ->and($schema['triggerActions']['filtersRemoveAll'])->toBe(['hidden' => true])
        ->and($schema['triggerActions']['columnManager']['variant'])->toBe('icon-button')
        ->and($schema['triggerActions']['groupRecords'])->toMatchArray(['label' => 'Group records', 'color' => 'primary'])
        ->and($schema['triggerActions']['reorderRecords']['label'])->toBe('Sort rows')
        ->and($schema['triggerActions']['reorderRecordsActive']['label'])->toBe('Done')
        ->and($schema['triggerActions'])->not->toHaveKey('columnManagerReset');
});

it('groups with one grouped query when groupQueryUsing() is set', function (): void {
    nicePosts();

    $table = Table::make()->query(Post::query())->columns([TextColumn::make('title'), TextColumn::make('status')])
        ->groups([Group::make('status')->groupQueryUsing(fn (Builder $query) => $query->groupBy('status'))])
        ->defaultGroup('status')
        ->groupsOnly();

    $groups = $table->state(niceState())->toSchema()['recordGroups'];

    expect(array_column($groups, 'key'))->toBe(['draft', 'published']);
});

// ---------------------------------------------------------------- Columns

it('serialises header tooltips, vertical alignment shortcuts and disabled clicks', function (): void {
    nicePosts();

    $schema = Table::make()->query(Post::query())->columns([
        TextColumn::make('title')->headerTooltip(fn (Table $table): string => 'Post title')->verticallyAlignStart()->url('/posts')->disabledClick(),
        TextColumn::make('status')->verticallyAlignEnd(fn (): bool => false),
        TextColumn::make('rating')->verticalAlignment(VerticalAlignment::Center),
    ])->recordUrl(fn (Post $record): string => "/posts/{$record->id}")->state(niceState())->toSchema();

    expect($schema['columns'][0])->toMatchArray(['headerTooltip' => 'Post title', 'verticalAlignment' => 'start'])
        ->and($schema['columns'][1])->not->toHaveKey('verticalAlignment')
        ->and($schema['columns'][2]['verticalAlignment'])->toBe('center')
        ->and($schema['records'][0]['cells']['title'])->toMatchArray(['disabledClick' => true])
        ->and($schema['records'][0]['cells']['title'])->not->toHaveKey('url')
        ->and($schema['records'][0]['cells']['status'])->not->toHaveKey('disabledClick');
});

it('injects the row loop into column closures', function (): void {
    nicePosts();

    $schema = Table::make()->query(Post::query())->defaultSort('title')->columns([
        TextColumn::make('position')->state(fn (stdClass $rowLoop): string => "{$rowLoop->iteration}/{$rowLoop->count}".($rowLoop->last ? ' last' : '')),
        TextColumn::make('index')->state(fn (?int $index): ?int => $index),
    ])->state(niceState())->toSchema();

    expect(array_map(fn (array $record) => $record['cells']['position']['state'], $schema['records']))->toBe(['1/3', '2/3', '3/3 last'])
        ->and($schema['records'][1]['cells']['index']['state'])->toBe('1')
        ->and(Table::makeRowLoop(0, 2))->toMatchArray(['first' => true, 'odd' => true, 'even' => false, 'remaining' => 1]);
});

it('breaks sort ties by key and can turn that off', function (): void {
    nicePosts();

    $table = fn (): Table => Table::make()->query(Post::query())->columns([TextColumn::make('rating')->sortable()]);

    expect(niceTitles($table(), ['sort' => 'rating', 'direction' => 'desc']))->toBe(['Beta', 'Alpha', 'Gamma'])
        ->and(niceTitles($table(), ['sort' => 'rating', 'direction' => 'asc']))->toBe(['Gamma', 'Alpha', 'Beta']);

    $orders = $table()->defaultKeySort(false)->state(niceState(['sort' => 'rating']))->getFilteredSortedQuery()?->getQuery()->orders;
    $unsorted = $table()->defaultKeySort(false)->state(niceState())->getFilteredSortedQuery()?->getQuery()->orders;

    expect(array_column($orders ?? [], 'column'))->toBe(['posts.rating'])
        ->and($unsorted)->toBeNull();
});

// ---------------------------------------------------------------- Text and image

it('formats iso dates and date tooltips on columns and entries', function (): void {
    nicePosts();

    $schema = Table::make()->query(Post::query())->defaultSort('title')->columns([
        TextColumn::make('published_at')->isoDate('LL')->dateTimeTooltip('Y-m-d H:i'),
        TextColumn::make('created_at')->isoDateTime()->tooltip('explicit')->isoDateTooltip(),
        TextColumn::make('updated_at')->isoTime('HH:mm')->date('Y'),
    ])->state(niceState())->toSchema();
    $cells = $schema['records'][0]['cells'];

    expect($cells['published_at'])->toMatchArray(['state' => 'January 5, 2026', 'tooltip' => '2026-01-05 10:00'])
        ->and($cells['created_at']['tooltip'])->toBe('explicit')
        ->and($cells['updated_at']['state'])->toBe(now()->format('Y'));

    $post = Post::query()->where('title', 'Gamma')->firstOrFail();
    $entry = TextEntry::make('published_at')->isoDate('D MMM YYYY')->sinceTooltip();
    Schema::make()->record($post)->components([$entry]);
    Carbon::setTestNow('2026-02-04 09:00:00');

    expect($entry->toSchema())->toMatchArray(['state' => '1 Feb 2026', 'tooltip' => '3 days ago']);

    Carbon::setTestNow();
});

it('exposes the character limit and expandable limited lists', function (): void {
    $column = TextColumn::make('title')->limit(5)
        ->tooltip(fn (TextColumn $column): ?string => mb_strlen((string) $column->getState()) > $column->getCharacterLimit() ? $column->getState() : null);
    $cell = $column->toCell(['title' => 'A long title']);

    expect($column->getCharacterLimit())->toBe(5)
        ->and($cell)->toMatchArray(['state' => 'A lon…', 'tooltip' => 'A long title'])
        ->and($column->toCell(['title' => 'Tiny']))->not->toHaveKey('tooltip');

    $list = TextColumn::make('tags')->limitList(2)->expandableLimitedList();
    $plain = TextColumn::make('tags')->limitList(2);

    expect($list->toCell(['tags' => ['a', 'b', 'c', 'd']])['state'])->toBe(['a', 'b', 'c', 'd'])
        ->and($list->toSchema())->toMatchArray(['expandableLimitedList' => true, 'listLimit' => 2])
        ->and($plain->toCell(['tags' => ['a', 'b', 'c', 'd']])['state'])->toHaveCount(3)
        ->and($plain->toSchema())->not->toHaveKey('expandableLimitedList')
        ->and(TextColumn::make('tags')->expandableLimitedList()->toSchema())->not->toHaveKey('expandableLimitedList');

    $entry = TextEntry::make('tags')->state(['x', 'y', 'z'])->limitList(1)->expandableLimitedList();
    Schema::make()->components([$entry]);

    expect($entry->toSchema())->toMatchArray(['state' => ['x', 'y', 'z'], 'expandableLimitedList' => true, 'listLimit' => 1]);
});

it('serialises image ring, overlap and extra img attributes', function (): void {
    $column = ImageColumn::make('avatar')->stacked()->ring(5)->overlap(fn (): int => 12)
        ->extraImgAttributes(fn (array $record): array => ['loading' => 'lazy', 'data-id' => $record['id']]);

    expect($column->toSchema())->toMatchArray(['ring' => 5, 'overlap' => 8])
        ->and($column->toCell(['id' => 7, 'avatar' => 'https://example.com/a.png'])['extraImgAttributes'])->toBe(['loading' => 'lazy', 'data-id' => 7])
        ->and(ImageColumn::make('avatar')->toSchema())->not->toHaveKeys(['ring', 'overlap']);

    $entry = ImageEntry::make('avatar')->state('https://example.com/a.png')->ring(0)->extraImgAttributes(['class' => 'shadow']);
    Schema::make()->components([$entry]);

    expect($entry->toSchema())->toMatchArray(['ring' => 0, 'extraImgAttributes' => ['class' => 'shadow']]);
});

it('serialises text input column affix icons', function (): void {
    $schema = TextInputColumn::make('slug')->prefixIcon('link')->suffixIcon('check', 'success')->toSchema();

    expect($schema)->toMatchArray(['prefixIcon' => 'link', 'suffixIcon' => 'check', 'suffixIconColor' => 'success'])
        ->and($schema)->not->toHaveKey('prefixIconColor');
});

// ---------------------------------------------------------------- Filters

it('modifies built-in filter fields but not custom forms', function (): void {
    $toggle = Filter::make('featured')->modifyFormFieldUsing(fn (Checkbox $field) => $field->label('Only featured'));
    $select = SelectFilter::make('status')->options(['draft' => 'Draft'])->modifyFormFieldUsing(fn (Select $field) => $field->native());
    $custom = Filter::make('custom')->schema([Checkbox::make('a')->label('A')])->modifyFormFieldUsing(fn (Checkbox $field) => $field->label('changed'));

    expect($toggle->getForm()[0]->getLabel())->toBe('Only featured')
        ->and($select->getForm()[0])->toBeInstanceOf(Select::class)
        ->and($select->toSchema()['schema'][0]['native'] ?? null)->toBeTrue()
        ->and($custom->getForm()[0]->getLabel())->toBe('A');
});

it('applies baseQuery() to the base query and when resolving records', function (): void {
    nicePosts();
    Post::query()->where('title', 'Beta')->firstOrFail()->delete();

    $table = fn (): Table => Table::make()->query(Post::query())->defaultSort('title')->columns([TextColumn::make('title')])->filters([
        Filter::make('trashed')
            ->query(fn (Builder $query) => $query->onlyTrashed())
            ->baseQuery(fn (Builder $query, array $data) => ($data['isActive'] ?? false) ? $query->withoutGlobalScopes([SoftDeletingScope::class]) : $query)
            ->excludeWhenResolvingRecord(),
    ]);

    $beta = Post::withTrashed()->where('title', 'Beta')->firstOrFail();

    expect(niceTitles($table()))->toBe(['Alpha', 'Gamma'])
        ->and(niceTitles($table(), ['filters' => ['trashed' => ['isActive' => '1']]]))->toBe(['Beta'])
        ->and($table()->getFilter('trashed')?->isExcludedWhenResolvingRecord())->toBeTrue()
        ->and($table()->state(niceState(['filters' => ['trashed' => ['isActive' => '1']]]))->getRecord($beta->id, withTrashed: false)?->getKey())->toBe($beta->id)
        ->and($table()->state(niceState())->getRecord($beta->id, withTrashed: false))->toBeNull();
});

it('offers an empty option on relationship select filters', function (): void {
    nicePosts();

    $table = fn (bool $multiple = false): Table => Table::make()->query(Post::query())->defaultSort('title')->columns([TextColumn::make('title')])->filters([
        SelectFilter::make('category')->relationship('category', 'name', hasEmptyOption: true)->emptyRelationshipOptionLabel('No category')->multiple($multiple),
    ]);
    $news = Category::query()->firstOrFail();

    $schema = $table()->state(niceState(['filters' => ['category' => ['value' => '__empty']]]))->toSchema();
    $options = $schema['filters'][0]['schema'][0]['options'];

    expect($options[0])->toBe(['value' => '__empty', 'label' => 'No category'])
        ->and(array_column($options, 'value'))->toBe(['__empty', (string) $news->id])
        ->and($schema['filters'][0]['schema'][0]['type'])->toBe('Select')
        ->and(array_column(array_column($schema['records'], 'cells'), 'title'))->toBe([['state' => 'Beta']])
        ->and($schema['filterIndicators'][0]['label'])->toBe('Category: No category')
        ->and(niceTitles($table(true), ['filters' => ['category' => ['values' => ['__empty', (string) $news->id]]]]))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and(niceTitles($table(), ['filters' => ['category' => ['value' => (string) $news->id]]]))->toBe(['Alpha', 'Gamma']);
});

it('serialises Indicator objects with removeField() and removable(false)', function (): void {
    nicePosts();

    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')])->filters([
        Filter::make('range')->schema([Checkbox::make('from'), Checkbox::make('until')])
            ->indicateUsing(fn (array $data): array => [
                Indicator::make('From')->removeField('from'),
                Indicator::make('Locked')->removable(false),
                'Plain',
            ]),
    ])->state(niceState(['filters' => ['range' => ['from' => '1']]]))->toSchema();

    expect($schema['filterIndicators'])->toBe([
        ['filter' => 'range', 'label' => 'From', 'removeField' => 'from'],
        ['filter' => 'range', 'label' => 'Locked', 'removable' => false],
        ['filter' => 'range', 'label' => 'Plain'],
    ]);
});

// ---------------------------------------------------------------- Query builder

it('customises query builder operators', function (): void {
    nicePosts();

    $constraint = TextConstraint::make('title')
        ->operators(['equals', 'contains', 'nope'])
        ->unshiftOperators([Operator::make('starts_with_a')->label('starts with A')->query(fn (Builder $query, string $column) => $query->where($column, 'like', 'A%'))])
        ->pushOperators(['is_blank']);

    $table = Table::make()->query(Post::query())->defaultSort('title')->columns([TextColumn::make('title')])->filters([
        QueryBuilder::make()->constraintPickerColumns(2)->constraintPickerWidth('lg')->constraints([
            $constraint,
            TextConstraint::make('author.name')->pushOperators([Operator::make('is_ada')->query(fn (Builder $query, string $column) => $query->where($column, 'Ada'))]),
            RelationshipConstraint::make('tags')->emptyable(false),
        ]),
    ]);
    $rule = fn (string $constraint, string $operator, mixed $value = null): array => ['filters' => ['queryBuilder' => ['query' => ['combinator' => 'and', 'rules' => [array_filter(['type' => 'constraint', 'constraint' => $constraint, 'operator' => $operator, 'value' => $value])]]]]];

    $schema = $table->state(niceState($rule('title', 'starts_with_a')))->toSchema();
    $field = $schema['filters'][0]['schema'][0];

    expect(array_column($field['constraints'][0]['operators'], 'name'))->toBe(['starts_with_a', 'equals', 'contains', 'is_blank'])
        ->and($field['constraints'][0]['operators'][0])->toBe(['name' => 'starts_with_a', 'label' => 'starts with A', 'input' => 'none'])
        ->and(array_column($field['constraints'][2]['operators'], 'name'))->toBe(['has', 'has_min', 'has_max'])
        ->and($field)->toMatchArray(['constraintPickerColumns' => ['default' => 2], 'constraintPickerWidth' => 'lg'])
        ->and(array_column(array_column(array_column($schema['records'], 'cells'), 'title'), 'state'))->toBe(['Alpha'])
        ->and($schema['filterIndicators'][0]['label'])->toBe('Title starts with A')
        ->and(niceTitles($table, $rule('title', 'starts_with', 'G')))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and(niceTitles($table, $rule('author.name', 'is_ada')))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and(niceTitles($table, $rule('tags', 'is_empty')))->toBe(['Alpha', 'Beta', 'Gamma']);
});

// ---------------------------------------------------------------- Summaries

it('shows minimal date and text differences in ranges', function (): void {
    nicePosts();

    $summary = fn (Range $range, string $column, array $filters = []): string => Table::make()->query(Post::query())
        ->columns([TextColumn::make($column)->summarize($range)])
        ->filters([SelectFilter::make('title')->multiple()->options(['Alpha' => 'Alpha', 'Beta' => 'Beta', 'Gamma' => 'Gamma'])])
        ->state(niceState($filters))->toSchema()['summaries']['all']['values'][$column][0]['value'];

    expect($summary(Range::make()->minimalDateTimeDifference(), 'published_at'))->toBe('January 5, 2026 – February 1, 2026')
        ->and($summary(Range::make()->minimalDateTimeDifference(), 'published_at', ['filters' => ['title' => ['values' => ['Alpha', 'Beta']]]]))->toBe('January 5, 2026 10:00 AM – January 5, 2026 6:00 PM')
        ->and($summary(Range::make()->minimalDateTimeDifference(), 'published_at', ['filters' => ['title' => ['values' => ['Gamma']]]]))->toBe('February 1, 2026 9:00 AM')
        ->and($summary(Range::make()->minimalTextualDifference(), 'title'))->toBe('A – G')
        ->and(Range::minimalTextualBounds('Banana', 'Bark'))->toBe(['Ban', 'Bar'])
        ->and(Range::minimalTextualBounds('Same', 'Same'))->toBe(['Same', 'Same']);
});

// ---------------------------------------------------------------- Actions

it('groups bulk actions, splits toolbar actions and modifies ungrouped record actions', function (): void {
    nicePosts();

    $table = Table::make()->query(Post::query())->columns([TextColumn::make('title')])
        ->recordActions([Action::make('view'), AlfaSix\Alfakit\Actions\ActionGroup::make([Action::make('edit')])])
        ->modifyUngroupedRecordActionsUsing(fn (Action $action) => $action->iconButton()->tooltip('Row action'))
        ->headerActions([Action::make('create')])
        ->toolbarActions([
            Action::make('export'),
            BulkAction::make('archive')->action(fn () => null),
            BulkActionGroup::make([BulkAction::make('delete')->action(fn () => null)]),
        ]);

    $schema = $table->state(niceState())->toSchema();

    expect(array_column($schema['headerActions'], 'name'))->toBe(['create', 'export'])
        ->and($schema['bulkActions'][0]['name'])->toBe('archive')
        ->and($schema['bulkActions'][1]['type'])->toBe('ActionGroup')
        ->and($schema['bulkActions'][1])->toMatchArray(['variant' => 'outline'])
        ->and($schema['bulkActions'][1]['label'])->toBeString()
        ->and($table->getBulkAction('delete'))->not->toBeNull()
        ->and($schema['records'][0]['actions'][0])->toMatchArray(['variant' => 'icon-button', 'tooltip' => 'Row action'])
        ->and($schema['records'][0]['actions'][1]['actions'][0])->not->toHaveKey('tooltip');

    $grouped = Table::make()->query(Post::query())->columns([TextColumn::make('title')])->groupedBulkActions([BulkAction::make('delete')]);

    expect($grouped->getBulkActions()[0])->toBeInstanceOf(BulkActionGroup::class)
        ->and($grouped->getBulkAction('delete'))->not->toBeNull();
});

it('resolves selected custom records through resolveSelectedRecordsUsing()', function (): void {
    $calls = [];
    $rows = [1 => ['id' => 1, 'title' => 'One'], 2 => ['id' => 2, 'title' => 'Two'], 3 => ['id' => 3, 'title' => 'Three']];

    $table = Table::make()->columns([TextColumn::make('title')])
        ->records(fn (): array => [1 => $rows[1]])
        ->resolveSelectedRecordsUsing(function (array $keys, bool $isTrackingDeselectedKeys, array $deselectedKeys) use (&$calls, $rows): array {
            $calls[] = [$keys, $isTrackingDeselectedKeys, $deselectedKeys];

            return $isTrackingDeselectedKeys ? $rows : array_intersect_key($rows, array_flip($keys)) + [99 => ['id' => 99, 'title' => 'Sneaky']];
        })
        ->bulkActions([BulkAction::make('delete')]);

    $selected = $table->state(niceState())->getSelectedRecords([2, 3]);
    $all = $table->getSelectedRecords([], all: true);

    expect($selected)->toBeInstanceOf(Collection::class)
        ->and($selected->pluck('title')->all())->toBe(['Two', 'Three'])
        ->and($all->pluck('title')->all())->toBe(['One', 'Two', 'Three'])
        ->and($calls)->toBe([[['2', '3'], false, []], [[], true, []]]);
});

it('limits custom record selections with maxSelectableRecords()', function (): void {
    $table = Table::make()->columns([TextColumn::make('title')])
        ->records(fn (): array => [])
        ->resolveSelectedRecordsUsing(fn (array $keys): array => array_map(fn ($key) => ['id' => $key, 'title' => "Row {$key}"], array_combine($keys, $keys)))
        ->maxSelectableRecords(1)
        ->bulkActions([BulkAction::make('delete')]);

    $table->state(niceState())->getSelectedRecords([1, 2]);
})->throws(Symfony\Component\HttpKernel\Exception\HttpException::class);

it('carries configuration arguments and tab badge tooltips', function (): void {
    expect(Table::make()->arguments(['status' => 'open'])->getArguments())->toBe(['status' => 'open'])
        ->and(AlfaSix\Alfakit\Schemas\Components\Tabs\Tab::make('Open')->badge(3)->badgeTooltip('Open orders')->getBadgeTooltip())->toBe('Open orders');
});
