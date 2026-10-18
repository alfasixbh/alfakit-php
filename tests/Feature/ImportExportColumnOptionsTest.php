<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/*
 * `ImportColumn::validationAttribute()`, `ExportColumn::hidden()` / `visible()` and the
 * `exists()` / `avg()` / `sum()` / `min()` / `max()` aggregates.
 */

class OptionsPostImporter extends Importer
{
    protected static ?string $model = Post::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')->label('Title')->validationAttribute('post title')->rules(['required']),
            ImportColumn::make('slug')->label('Slug')->rules(['required']),
            ImportColumn::make('tags_list')->array('|')->validationAttribute(fn (): string => 'tag')->nestedRecursiveRules(['integer']),
        ];
    }

    public function resolveRecord(): ?Model
    {
        return new Post;
    }
}

class OptionsPostExporter extends Exporter
{
    protected static ?string $model = Post::class;

    public static bool $showSecret = false;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('title'),
            ExportColumn::make('status')->hidden(fn (): bool => ! static::$showSecret),
            ExportColumn::make('slug')->visible(false),
            ExportColumn::make('comments_count')->counts('comments'),
            ExportColumn::make('comments_exists')->exists('comments'),
            ExportColumn::make('comments_avg_sort')->avg('comments', 'sort'),
            ExportColumn::make('comments_sum_sort')->sum('comments', 'sort'),
            ExportColumn::make('comments_min_sort')->min('comments', 'sort'),
            ExportColumn::make('comments_max_sort')->max(['comments' => fn (Builder $query) => $query->where('sort', '<', 10)], 'sort'),
        ];
    }
}

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();
    OptionsPostExporter::$showSecret = false;
    $this->actingAs(User::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'password']));
});

it('names import columns in validation messages with validationAttribute()', function (): void {
    $errors = OptionsPostImporter::test()->import(['title' => '', 'slug' => '', 'tags_list' => 'x'])->getErrors();

    expect($errors['title'][0])->toContain('post title')
        ->and($errors['slug'][0])->toContain('Slug')
        ->and($errors['tags_list.0'][0])->toContain('tag');
});

it('falls back to the label as validation attribute', function (): void {
    expect(ImportColumn::make('email_address')->getValidationAttribute())->toBe('Email address')
        ->and(ImportColumn::make('email')->label('E-mail')->getValidationAttribute())->toBe('E-mail');
});

it('leaves hidden export columns out of the export', function (): void {
    expect(OptionsPostExporter::test()->headers())->not->toContain('Status')->not->toContain('Slug')->toContain('Title');

    OptionsPostExporter::$showSecret = true;

    expect(OptionsPostExporter::test()->headers())->toContain('Status');
});

it('does not offer or accept hidden columns in the export action', function (): void {
    $action = new class('export') extends ExportAction
    {
        public function columnMapFor(array $data): array
        {
            return $this->resolveColumnMap($data);
        }

        public function columnOptions(): array
        {
            return array_map(fn (ExportColumn $column): string => $column->getName(), $this->getVisibleExportColumns());
        }
    };
    $action->exporter(OptionsPostExporter::class);

    expect($action->columnOptions())->not->toContain('status')->not->toContain('slug')
        ->and(array_keys($action->columnMapFor(['columns' => ['title', 'status', 'slug']])))->toBe(['title']);
});

it('exports relationship aggregates', function (): void {
    $post = Post::query()->create(['title' => 'A', 'slug' => 'a-'.uniqid()]);
    $empty = Post::query()->create(['title' => 'B', 'slug' => 'b-'.uniqid()]);

    foreach ([2, 4, 12] as $sort) {
        Comment::query()->create(['post_id' => $post->id, 'author_name' => 'x', 'body' => 'y', 'sort' => $sort]);
    }

    $exporter = OptionsPostExporter::test()->getExporter();
    $records = $exporter->applyEagerLoading(Post::query())->orderBy('id')->get();

    [$row, $emptyRow] = [$exporter->getRow($records[0]), $exporter->getRow($records[1])];

    // title, count, exists, avg, sum, min, max (max scoped to sort < 10)
    expect($row[0])->toBe('A')
        ->and((int) $row[1])->toBe(3)
        ->and((bool) $row[2])->toBeTrue()
        ->and((float) $row[3])->toBe(6.0)
        ->and((int) $row[4])->toBe(18)
        ->and((int) $row[5])->toBe(2)
        ->and((int) $row[6])->toBe(4)
        ->and((int) $emptyRow[1])->toBe(0)
        ->and((bool) $emptyRow[2])->toBeFalse()
        ->and($emptyRow[3])->toBeNull();
});

it('keeps counts() working with the aggregate attribute name', function (): void {
    $column = ExportColumn::make('blog_count')->counts('blogPosts');

    expect($column->getCountedRelationship())->toBe('blogPosts')
        ->and($column->getAggregateAttribute())->toBe('blog_posts_count')
        ->and(ExportColumn::make('x')->avg('comments', 'sort')->getAggregateAttribute())->toBe('comments_avg_sort')
        ->and(ExportColumn::make('x')->exists('comments')->getAggregateAttribute())->toBe('comments_exists');
});
