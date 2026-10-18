<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Enums\ColumnManagerLayout;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use AlfaSix\Alfakit\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\PageTable\PostStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/*
 * Filament 5.x parity: column manager trigger modal options, grouped query builder
 * constraints, `preventFormulaInjection()` compatibility and `ImportColumn::enum()`.
 */

enum ImportPriority: int
{
    case Low = 1;
    case High = 2;
}

class EnumPostImporter extends Importer
{
    protected static ?string $model = Post::class;

    protected static bool $shouldPreventFormulaInjection = true;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')->rules(['required']),
            ImportColumn::make('status')->enum(PostStatus::class),
            ImportColumn::make('rating')->enum(ImportPriority::class),
            ImportColumn::make('tags_list')->multiple('|')->enum(PostStatus::class)->fillRecordUsing(fn () => null),
        ];
    }

    public function resolveRecord(): ?Model
    {
        return new Post(['slug' => 'post-'.uniqid()]);
    }
}

function f5ParityState(): TableState
{
    return TableState::fromRequest(Request::create('/', 'GET'));
}

// ---------------------------------------------------------------- Column manager modal

it('sends modal options of the column manager trigger action', function (): void {
    $table = fn (Closure $trigger) => Table::make()->query(Post::query())
        ->columns([TextColumn::make('title')->toggleable(), TextColumn::make('status')->toggleable()])
        ->columnManagerLayout(ColumnManagerLayout::Modal)
        ->columnManagerTriggerAction($trigger)
        ->state(f5ParityState())->toSchema()['triggerActions']['columnManager'];

    expect($table(fn (Action $action) => $action->slideOver()->modalHeading('Pick columns'))['modal'])
        ->toBe(['heading' => 'Pick columns', 'width' => 'md', 'slideOver' => true])
        ->and($table(fn (Action $action) => $action->modalWidth(Width::FourExtraLarge)->modalDescription('Shown in the table'))['modal'])
        ->toBe(['description' => 'Shown in the table', 'width' => '4xl'])
        ->and($table(fn (Action $action) => $action->slideOver()->slideOverPosition(AlfaSix\Alfakit\Support\Enums\SlideOverPosition::Start))['modal'])
        ->toMatchArray(['slideOver' => true, 'slideOverPosition' => 'start'])
        // Only the look of a plain restyle; no modal options.
        ->and($table(fn (Action $action) => $action->label('Columns')))->toBe(['label' => 'Columns', 'color' => 'gray'])
        ->and($table(fn (Action $action) => $action->label('Columns')))->not->toHaveKey('modal');
});

it('sends modal options only for the column manager trigger', function (): void {
    $schema = Table::make()->query(Post::query())->columns([TextColumn::make('title')])
        ->filtersTriggerAction(fn (Action $action) => $action->slideOver())
        ->state(f5ParityState())->toSchema();

    expect($schema['triggerActions']['filters'])->not->toHaveKey('modal');
});

// ---------------------------------------------------------------- Grouped constraints

it('groups query builder constraints under labels', function (): void {
    $filter = QueryBuilder::make()->constraints([
        'Content' => [TextConstraint::make('title'), TextConstraint::make('slug')],
        TextConstraint::make('status'),
        'Numbers' => [NumberConstraint::make('rating')],
    ]);

    $field = Table::make()->query(Post::query())->columns([TextColumn::make('title')])->filters([$filter])
        ->state(f5ParityState())->toSchema()['filters'][0]['schema'][0];

    expect(array_keys($filter->getConstraints()))->toBe(['title', 'slug', 'status', 'rating'])
        ->and(array_keys($filter->getGroupedConstraints()))->toBe([0, 'Content', 'Numbers'])
        ->and(array_map(fn ($constraint) => $constraint->getName(), $filter->getGroupedConstraints()['Content']))->toBe(['title', 'slug'])
        ->and(array_column($field['constraints'], 'name'))->toBe(['status', 'title', 'slug', 'rating'])
        ->and(array_map(fn (array $constraint) => $constraint['group'] ?? null, $field['constraints']))->toBe([null, 'Content', 'Content', 'Numbers']);
});

it('filters with a grouped constraint', function (): void {
    Post::query()->create(['title' => 'Alpha', 'slug' => 'alpha']);
    Post::query()->create(['title' => 'Beta', 'slug' => 'beta']);

    $state = TableState::fromRequest(Request::create('/', 'GET', ['filters' => ['queryBuilder' => ['query' => ['rules' => [
        ['type' => 'constraint', 'constraint' => 'title', 'operator' => 'contains', 'value' => 'alp'],
    ]]]]]));

    $records = Table::make()->query(Post::query())->columns([TextColumn::make('title')])
        ->filters([QueryBuilder::make()->constraints(['Content' => [TextConstraint::make('title')]])])
        ->state($state)->toSchema()['records'];

    expect(array_map(static fn (array $record): string => $record['cells']['title']['state'], $records))->toBe(['Alpha']);
});

// ---------------------------------------------------------------- Formula injection

it('accepts preventFormulaInjection() calls, protection being always on', function (): void {
    $column = ExportColumn::make('title')->preventFormulaInjection(false);

    Importer::preventFormulaInjection();

    expect($column)->toBeInstanceOf(ExportColumn::class)
        ->and($column->shouldPreventFormulaInjection())->toBeTrue()
        ->and(Importer::shouldPreventFormulaInjection())->toBeTrue()
        ->and(EnumPostImporter::shouldPreventFormulaInjection())->toBeTrue()
        ->and(AlfaSix\Alfakit\Actions\Exports\Writers\CsvWriter::escape('=1+1'))->toBe("'=1+1");
});

// ---------------------------------------------------------------- ImportColumn::enum()

it('validates import columns against a backed enum', function (): void {
    $column = ImportColumn::make('status')->enum(PostStatus::class);

    expect($column->getEnum())->toBe(PostStatus::class)
        ->and($column->getValidationRules())->toHaveCount(2)
        ->and($column->getValidationRules()[0])->toBe('nullable')
        ->and($column->getExamples())->toBe(['draft', 'published', 'archived'])
        ->and(ImportColumn::make('status')->enum(PostStatus::class)->examples(['draft'])->getExamples())->toBe(['draft'])
        ->and(ImportColumn::make('status')->enum(fn (): string => PostStatus::class)->getExamples())->toBe([])
        ->and(ImportColumn::make('tags')->multiple()->enum(PostStatus::class)->getNestedRecursiveValidationRules())->toHaveCount(1)
        ->and(ImportColumn::make('tags')->multiple()->enum(PostStatus::class)->getValidationRules())->toBe([])
        ->and(ImportColumn::make('rating')->enum(ImportPriority::class)->castState(' 2 '))->toBe(2)
        ->and(ImportColumn::make('status')->enum(PostStatus::class)->castState('draft'))->toBe('draft');
});

it('refuses non-backed enums', function (): void {
    ImportColumn::make('status')->enum(UnitOnlyStatus::class)->getValidationRules(); // @phpstan-ignore argument.type
})->throws(InvalidArgumentException::class);

enum UnitOnlyStatus
{
    case Draft;
}

it('imports enum values and reports invalid ones', function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();
    test()->actingAs(User::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'password']));

    $valid = EnumPostImporter::test()->import(['title' => 'Hello', 'status' => 'published', 'rating' => '2', 'tags_list' => 'draft|archived']);

    expect($valid->getErrors())->toBe([])
        ->and(Post::query()->where('title', 'Hello')->value('status'))->toBe('published')
        ->and((int) Post::query()->where('title', 'Hello')->value('rating'))->toBe(2);

    $errors = EnumPostImporter::test()->import(['title' => 'Bad', 'status' => 'nope', 'rating' => '7', 'tags_list' => 'draft|nope'])->getErrors();

    expect($errors)->toHaveKeys(['status', 'rating', 'tags_list.1'])
        // Blank enum cells pass validation; only the missing title fails.
        ->and(array_keys(EnumPostImporter::test()->import(['title' => '', 'status' => '', 'rating' => '', 'tags_list' => ''])->getErrors()))->toBe(['title']);
});

// ---------------------------------------------------------------- Export aggregates

class ExpressionPostExporter extends AlfaSix\Alfakit\Actions\Exports\Exporter
{
    protected static ?string $model = Post::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('title'),
            ExportColumn::make('double_sort')->sum('comments', Illuminate\Support\Facades\DB::raw('sort * 2')),
        ];
    }
}

it('exports aggregates of a raw expression column', function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();
    test()->actingAs(User::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'password']));
    $post = Post::query()->create(['title' => 'A', 'slug' => 'a-'.uniqid()]);

    foreach ([2, 4] as $sort) {
        AlfaSix\Alfakit\Tests\Fixtures\Models\Comment::query()->create(['post_id' => $post->id, 'author_name' => 'x', 'body' => 'y', 'sort' => $sort]);
    }

    $exporter = ExpressionPostExporter::test()->getExporter();
    $record = $exporter->applyEagerLoading(Post::query())->firstOrFail();

    expect((int) $exporter->getRow($record)[1])->toBe(12);
});
