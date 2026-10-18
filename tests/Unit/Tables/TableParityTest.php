<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\ImageColumn;
use AlfaSix\Alfakit\Tables\Columns\SelectColumn;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Enums\FiltersLayout;
use AlfaSix\Alfakit\Tables\Filters\Filter;
use AlfaSix\Alfakit\Tables\Filters\SelectFilter;
use AlfaSix\Alfakit\Tables\Grouping\Group;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function parityPosts(): array
{
    $ada = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
    $bob = User::query()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

    return [
        $ada,
        $bob,
        Post::query()->create(['title' => 'Alpha One', 'slug' => 'first', 'author_id' => $ada->id, 'status' => 'draft']),
        Post::query()->create(['title' => 'Beta Two', 'slug' => 'second', 'author_id' => $bob->id, 'status' => 'draft']),
        Post::query()->create(['title' => 'Gamma Alpha', 'slug' => 'third', 'author_id' => $ada->id, 'status' => 'published']),
    ];
}

function parityState(array $query = []): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET', $query));
}

function parityTable(array $columns = [], ?Closure $configure = null): Table
{
    $table = Table::make()->query(Post::query())->defaultSort('id')->columns($columns ?: [TextColumn::make('title')->searchable()]);

    return ($configure === null ? $table : $configure($table))->state(parityState());
}

function parityKeys(Table $table, array $query): array
{
    return array_column($table->state(parityState($query))->toSchema()['records'], 'key');
}

// ---------------------------------------------------------------- #12 SelectColumn

it('serialises a combobox select column with sanitised HTML labels', function (): void {
    parityPosts();

    $schema = parityTable([
        SelectColumn::make('status')
            ->options(['draft' => '<b>Draft</b><script>alert(1)</script>', 'published' => 'Published'])
            ->native(fn (): bool => false)
            ->searchableOptions()
            ->optionsLimit(20)
            ->allowOptionsHtml(),
    ])->toSchema();

    $column = $schema['columns'][0];

    expect($column['native'])->toBeFalse()
        ->and($column['searchableOptions'])->toBeTrue()
        ->and($column['optionsLimit'])->toBe(20)
        ->and($column['allowOptionsHtml'])->toBeTrue()
        ->and($column['options'][0]['label'])->toContain('<b>Draft</b>')
        ->and($column['options'][0]['label'])->not->toContain('script')
        ->and($column)->not->toHaveKey('optionsSearchPath');
});

it('keeps native select columns plain', function (): void {
    parityPosts();

    $column = parityTable([
        SelectColumn::make('status')->options(['draft' => '<b>Draft</b>'])->allowOptionsHtml()->searchableOptions(),
    ])->toSchema()['columns'][0];

    expect($column)->not->toHaveKey('native')
        ->and($column)->not->toHaveKey('searchableOptions')
        ->and($column)->not->toHaveKey('allowOptionsHtml')
        ->and($column['options'][0]['label'])->toBe('Draft');
});

it('lists relationship options and validates against the relationship query', function (): void {
    [$ada, $bob, $post] = parityPosts();

    $table = parityTable([
        SelectColumn::make('author_id')->optionsRelationship('author', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('name', '!=', 'Bob')),
    ]);

    expect($table->toSchema()['columns'][0]['options'])->toBe([['value' => (string) $ada->id, 'label' => 'Ada']]);

    $column = $table->getColumn('author_id');

    expect(fn () => $column->updateState($post, $bob->id))->toThrow(ValidationException::class);

    $column->updateState($post, $ada->id);

    expect($post->fresh()->author_id)->toBe($ada->id);
});

it('searches relationship options on the server through the filters schema', function (): void {
    [$ada, $bob] = parityPosts();

    $table = parityTable([
        SelectColumn::make('author_id')->native(false)->optionsRelationship('author', 'name')->searchableOptions(['name', 'email']),
    ]);

    $schema = $table->toSchema();

    expect($schema['columns'][0]['options'])->toBe([])
        ->and($schema['columns'][0]['optionsSearchPath'])->toBe('__columnOptions.author_id')
        ->and($schema['records'][0]['cells']['author_id']['optionLabel'])->toBe('Ada')
        ->and($schema['records'][1]['cells']['author_id']['optionLabel'])->toBe('Bob');

    $field = $table->getFiltersSchema()->getFieldByStatePath('__columnOptions.author_id');

    expect($field)->not->toBeNull()
        ->and($field->getSearchResults('bob@'))->toBe([(string) $bob->id => 'Bob']);
});

it('caps searched options with optionsLimit and preloads them on request', function (): void {
    parityPosts();

    $table = parityTable([
        SelectColumn::make('author_id')->native(false)->optionsRelationship('author', 'name')->searchableOptions()->preloadOptions()->optionsLimit(1),
    ]);

    $column = $table->toSchema()['columns'][0];

    expect($column['options'])->toHaveCount(1)
        ->and($column)->not->toHaveKey('optionsSearchPath');
});

it('leaves the record out of its own options with ignoreRecord', function (): void {
    [$ada, , $post] = parityPosts();

    // The post and the first user share key 1: the post's own key is not a valid choice.
    $column = parityTable([
        SelectColumn::make('author_id')->optionsRelationship('author', 'name', ignoreRecord: true),
    ])->getColumn('author_id');

    expect($post->getKey())->toBe($ada->getKey())
        ->and(fn () => $column->updateState($post, $ada->id))->toThrow(ValidationException::class)
        ->and($column->toSchema()['ignoreRecord'])->toBeTrue();
});

it('uses custom option search and labels', function (): void {
    parityPosts();

    $table = parityTable([
        SelectColumn::make('status')
            ->native(false)
            ->searchableOptions()
            ->getOptionsSearchResultsUsing(fn (string $search): array => array_filter(['draft' => 'Draft', 'published' => 'Published'], fn (string $label): bool => str_contains(strtolower($label), strtolower($search))))
            ->getOptionLabelUsing(fn (string $value): ?string => ['draft' => 'Draft', 'published' => 'Published'][$value] ?? null),
    ]);

    $schema = $table->toSchema();

    expect($schema['records'][0]['cells']['status']['optionLabel'])->toBe('Draft')
        ->and($table->getFiltersSchema()->getFieldByStatePath('__columnOptions.status')->getSearchResults('pub'))->toBe(['published' => 'Published'])
        ->and(fn () => $table->getColumn('status')->updateState(Post::query()->first(), 'bogus'))->toThrow(ValidationException::class);
});

it('does not expose disabled select columns to the options endpoint', function (): void {
    parityPosts();

    $table = parityTable([
        SelectColumn::make('author_id')->native(false)->optionsRelationship('author', 'name')->searchableOptions()->disabled(),
    ]);

    expect($table->getFiltersSchema()->getFieldByStatePath('__columnOptions.author_id'))->toBeNull();
});

// ---------------------------------------------------------------- #13 Persistence keys

it('turns every kind of session persistence on with persistInSession', function (): void {
    expect(Table::make()->persistInSession()->getPersistedStateKeys())->toBe(['filters', 'search', 'columnSearch', 'sort', 'perPage', 'columns', 'group'])
        ->and(Table::make()->persistInSession()->persistSortInSession(false)->persistColumnsInSession(fn (): bool => false)->getPersistedStateKeys())->toBe(['filters', 'search', 'columnSearch', 'perPage', 'group'])
        ->and(Table::make()->persistInSession(false)->persistGroupInSession()->getPersistedStateKeys())->toBe(['group'])
        ->and(Table::make()->persistRecordsPerPageInSession()->getPersistedStateKeys())->toBe(['perPage'])
        ->and(Table::make()->getPersistedStateKeys())->toBe([]);
});

// ---------------------------------------------------------------- #14 Stacked on mobile

it('flags tables stacked on mobile', function (): void {
    parityPosts();

    expect(parityTable(configure: fn (Table $t) => $t->stackedOnMobile())->toSchema()['stackedOnMobile'])->toBeTrue()
        ->and(parityTable(configure: fn (Table $t) => $t->stackedOnMobile(fn (): bool => false))->toSchema())->not->toHaveKey('stackedOnMobile');
});

// ---------------------------------------------------------------- #15 Selection safeguards

it('limits the selection with maxSelectableRecords', function (): void {
    [, , $a, $b, $c] = parityPosts();

    $table = parityTable(configure: fn (Table $t) => $t->maxSelectableRecords(2)->bulkActions([BulkAction::make('touch')->action(fn () => null)]));

    expect($table->toSchema()['maxSelectableRecords'])->toBe(2)
        ->and($table->getSelectedRecords([$a->id, $b->id]))->toHaveCount(2)
        ->and(fn () => $table->getSelectedRecords([$a->id, $b->id, $c->id]))->toThrow(HttpException::class)
        ->and(fn () => $table->getSelectedRecords([], all: true))->toThrow(HttpException::class);

    $action = $table->getBulkAction('touch');
    $action->selectedRecordsQuery(Post::query()->whereKey([$a->id, $b->id, $c->id]));

    expect(fn () => $action->execute())->toThrow(HttpException::class);
});

it('keeps the selection within one group with selectGroupsOnly', function (): void {
    [, , $a, $b, $c] = parityPosts();

    $table = Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')])
        ->groups([Group::make('status')])
        ->defaultGroup('status')
        ->selectGroupsOnly()
        ->bulkActions([BulkAction::make('touch')->action(fn () => null)])
        ->state(parityState());

    expect($table->toSchema()['selectGroupsOnly'])->toBeTrue()
        ->and($table->getSelectedRecords([$a->id, $b->id]))->toHaveCount(2)
        ->and(fn () => $table->getSelectedRecords([$a->id, $c->id]))->toThrow(HttpException::class);

    $action = $table->getBulkAction('touch');
    $action->selectedRecordsQuery(Post::query()->whereKey([$a->id, $c->id]));

    expect(fn () => $action->execute())->toThrow(HttpException::class);

    // Without grouping the option has no effect.
    expect(parityTable(configure: fn (Table $t) => $t->selectGroupsOnly()->bulkActions([BulkAction::make('x')]))->toSchema())->not->toHaveKey('selectGroupsOnly');
});

it('hands bulk actions the selected keys with fetchSelectedRecords(false)', function (): void {
    [, , $a, $b, $c] = parityPosts();
    $received = new ArrayObject;

    $table = parityTable(configure: fn (Table $t) => $t
        ->checkIfRecordIsSelectableUsing(fn (Post $record): bool => $record->id !== $c->id)
        ->bulkActions([BulkAction::make('ids')->fetchSelectedRecords(false)->action(function (Collection $records) use ($received): void {
            $received['records'] = $records;
        })]));

    $action = $table->getBulkAction('ids');
    $action->selectedRecordsQuery(Post::query()->whereKey([$a->id, $b->id, $c->id]), fn ($record): bool => $table->isRecordSelectable($record));
    $action->execute();

    expect($received['records'])->not->toBeInstanceOf(EloquentCollection::class)
        ->and($received['records']->all())->toBe([$a->id, $b->id]);
});

it('reports bulk processing failures with counts', function (): void {
    [, , $a, $b, $c] = parityPosts();

    $table = parityTable(configure: fn (Table $t) => $t->bulkActions([
        BulkAction::make('publish')
            ->action(function (Collection $records, BulkAction $action): void {
                foreach ($records as $record) {
                    if ($record->status === 'published') {
                        $action->reportBulkProcessingFailure('published', message: fn (int $failureCount, int $totalCount): string => "{$failureCount} of {$totalCount} were already published.");

                        continue;
                    }
                }
            })
            ->failureNotificationTitle(fn (int $successCount, int $failureCount, int $totalCount): string => "{$successCount} done, {$failureCount} failed of {$totalCount}")
            ->successNotificationTitle('All done')
            ->after(fn (BulkAction $action) => $action->sendSuccessNotification()),
    ]));

    $action = $table->getBulkAction('publish');
    $action->selectedRecordsQuery(Post::query()->whereKey([$a->id, $b->id, $c->id]));
    $action->execute();

    $notifications = Notification::getFlashed();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['title'])->toBe('2 done, 1 failed of 3')
        ->and($notifications[0]['body'])->toBe('1 of 3 were already published.')
        ->and($action->getBulkProcessingSuccessCount())->toBe(2);
});

it('notifies about failures even when the action sends nothing itself', function (): void {
    [, , $a, $b] = parityPosts();

    $table = parityTable(configure: fn (Table $t) => $t->bulkActions([
        BulkAction::make('fail')->action(function (Collection $records, BulkAction $action): void {
            $records->each(fn () => $action->reportBulkProcessingFailure());
        }),
    ]));

    $action = $table->getBulkAction('fail');
    $action->selectedRecordsQuery(Post::query()->whereKey([$a->id, $b->id]));
    $action->execute();

    $notifications = Notification::getFlashed();

    expect($notifications)->toHaveCount(1)
        ->and($notifications[0]['status'])->toBe('danger');
});

it('gives record actions the selection with accessSelectedRecords', function (): void {
    [, , $a, $b, $c] = parityPosts();
    $seen = new ArrayObject;

    $table = parityTable(configure: fn (Table $t) => $t
        ->selectable()
        ->checkIfRecordIsSelectableUsing(fn (Post $record): bool => $record->id !== $c->id)
        ->recordActions([
            Action::make('copy')->accessSelectedRecords()->action(function (Post $record, Collection $selectedRecords) use ($seen): void {
                $seen['value'] = [$record->id, $selectedRecords->modelKeys()];
            }),
        ]));

    $schema = $table->toSchema();
    expect($schema['selectable'])->toBeTrue()
        ->and($schema['records'][0]['actions'][0]['accessSelectedRecords'])->toBeTrue();

    $action = $table->getAction('copy');
    $action->record($a);
    // Unselectable and unknown keys are dropped; keys are resolved within the table query.
    $action->execute(arguments: [Action::SELECTED_RECORDS_ARGUMENT => [$b->id, $c->id, 999]]);

    expect($seen['value'] ?? null)->toBe([$a->id, [$b->id]]);

    // Without accessSelectedRecords() the argument is ignored.
    expect(Action::make('x')->getSelectedRecords())->toHaveCount(0);
});

it('shows checkboxes without bulk actions only when asked', function (): void {
    parityPosts();

    expect(parityTable()->toSchema())->not->toHaveKey('selectable')
        ->and(parityTable(configure: fn (Table $t) => $t->selectable())->toSchema()['selectable'])->toBeTrue()
        ->and(parityTable(configure: fn (Table $t) => $t->selectable(false)->bulkActions([BulkAction::make('x')]))->toSchema())->not->toHaveKey('selectable');
});

// ---------------------------------------------------------------- #16 Search

it('searches the whole phrase with splitSearchTerms(false)', function (): void {
    parityPosts();

    expect(parityKeys(parityTable(), ['search' => 'Alpha One']))->toBe([1])
        ->and(parityKeys(parityTable(), ['search' => 'One Alpha']))->toBe([1])
        ->and(parityKeys(parityTable(configure: fn (Table $t) => $t->splitSearchTerms(false)), ['search' => 'One Alpha']))->toBe([])
        ->and(parityKeys(parityTable(configure: fn (Table $t) => $t->splitSearchTerms(false)), ['search' => 'Gamma Alpha']))->toBe([3]);
});

it('splits individual column searches when asked', function (): void {
    parityPosts();

    $columns = fn (?bool $split = null): array => [TextColumn::make('title')->searchable(isIndividual: true)->splitIndividualSearchTerms($split)];

    expect(parityKeys(parityTable($columns()), ['columnSearch' => ['title' => 'One Alpha']]))->toBe([])
        ->and(parityKeys(parityTable($columns(), fn (Table $t) => $t->splitIndividualSearchTerms()), ['columnSearch' => ['title' => 'One Alpha']]))->toBe([1])
        ->and(parityKeys(parityTable($columns(true)), ['columnSearch' => ['title' => 'One Alpha']]))->toBe([1])
        ->and(parityKeys(parityTable($columns(false), fn (Table $t) => $t->splitIndividualSearchTerms()), ['columnSearch' => ['title' => 'One Alpha']]))->toBe([]);
});

it('searches extra attributes with a table-level searchable()', function (): void {
    parityPosts();

    $table = fn (): Table => parityTable([TextColumn::make('title')], fn (Table $t) => $t->searchable(['slug', 'author.email']));

    expect($table()->toSchema()['searchable'])->toBeTrue()
        ->and(parityKeys($table(), ['search' => 'second']))->toBe([2])
        ->and(parityKeys($table(), ['search' => 'bob@']))->toBe([2])
        ->and(parityTable(configure: fn (Table $t) => $t->searchable(false))->toSchema())->not->toHaveKey('searchable')
        ->and(parityKeys(parityTable(configure: fn (Table $t) => $t->searchable(false)), ['search' => 'Beta']))->toBe([1, 2, 3]);
});

it('replaces the search with searchUsing()', function (): void {
    parityPosts();
    $seen = new ArrayObject;

    $table = fn (): Table => parityTable([TextColumn::make('title')], fn (Table $t) => $t->searchUsing(function (Builder $query, string $search) use ($seen): void {
        $seen['search'] = $search;
        $query->where('slug', $search);
    }));

    expect($table()->toSchema()['searchable'])->toBeTrue()
        ->and(parityKeys($table(), ['search' => 'third']))->toBe([3])
        ->and($seen['search'] ?? null)->toBe('third');
});

// ---------------------------------------------------------------- #17 Filter layouts

it('serialises sidebar layouts and filters form sizing', function (): void {
    parityPosts();

    $schema = parityTable(configure: fn (Table $t) => $t
        ->filters([Filter::make('mine')->query(fn (Builder $q) => $q)], FiltersLayout::BeforeContentCollapsible)
        ->filtersFormWidth(Width::Large)
        ->filtersFormMaxHeight('400px')
        ->hiddenFilterIndicators())->toSchema();

    expect($schema['filtersLayout'])->toBe('before-content-collapsible')
        ->and($schema['filtersFormWidth'])->toBe('lg')
        ->and($schema['filtersFormMaxHeight'])->toBe('400px')
        ->and($schema['hiddenFilterIndicators'])->toBeTrue()
        ->and(FiltersLayout::AfterContent->value)->toBe('after-content')
        ->and(FiltersLayout::AfterContentCollapsible->value)->toBe('after-content-collapsible')
        ->and(FiltersLayout::BeforeContent->value)->toBe('before-content');
});

it('only accepts a CSS length as the filters form max height', function (): void {
    parityPosts();

    expect(parityTable(configure: fn (Table $t) => $t->filtersFormMaxHeight('1px;background:url(x)'))->toSchema())->not->toHaveKey('filtersFormMaxHeight')
        ->and(parityTable(configure: fn (Table $t) => $t->filtersFormMaxHeight(fn (): string => '60vh'))->toSchema()['filtersFormMaxHeight'])->toBe('60vh');
});

it('lays the filters form out with filtersFormSchema', function (): void {
    parityPosts();
    $received = new ArrayObject;

    $table = parityTable(configure: fn (Table $t) => $t
        ->filters([
            SelectFilter::make('author_id')->relationship('author', 'name')->searchable(),
            Filter::make('mine')->query(fn (Builder $q) => $q),
        ])
        ->filtersFormSchema(function (array $filters) use ($received): array {
            $received['keys'] = array_keys($filters);

            return [Section::make('Who')->schema([$filters['author_id']]), $filters['mine']];
        }));

    $layout = $table->toSchema()['filtersFormSchema'];

    expect($received['keys'] ?? null)->toBe(['author_id', 'mine'])
        ->and($layout[0]['type'])->toBe('Section')
        ->and($layout[0]['schema'][0]['key'])->toBe('filter:author_id')
        ->and($layout[1]['key'])->toBe('filter:mine')
        // The options endpoint still finds the filter's select inside the section.
        ->and($table->getFiltersSchema()->getFieldByStatePath('author_id.values') ?? $table->getFiltersSchema()->getFieldByStatePath('author_id.value'))->not->toBeNull();
});

// ---------------------------------------------------------------- #18 Image alt

it('gives image columns alt text from a closure', function (): void {
    parityPosts();

    $schema = parityTable([
        ImageColumn::make('cover')->defaultImageUrl('/x.png')->alt(fn (Post $record): string => "Cover of {$record->title}"),
    ])->toSchema();

    expect($schema['records'][0]['cells']['cover']['alt'])->toBe('Cover of Alpha One')
        ->and(parityTable([ImageColumn::make('cover')->defaultImageUrl('/x.png')])->toSchema()['records'][0]['cells']['cover'])->not->toHaveKey('alt');
});
