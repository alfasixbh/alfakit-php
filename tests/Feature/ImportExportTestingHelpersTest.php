<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Actions\ExportBulkAction;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\ImportAction;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\PostExporter;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\PostImporter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\Imports\ParityPostImporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\ExpectationFailedException;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();

    Storage::fake('local');
    config(['alfakit.uploads.temporary_disk' => 'local', 'queue.default' => 'sync']);
    ParityPostImporter::$traitHooks = [];

    Table::configureUsing(static function (Table $table): void {
        $table->headerActions([
            ImportAction::make()->importer(PostImporter::class)->chunkSize(2),
            ExportAction::make()->exporter(PostExporter::class)->chunkSize(2),
        ]);
    });
});

afterEach(function (): void {
    Table::clearConfigurations();
});

function helpersUser(): User
{
    return User::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'password']);
}

// ---------------------------------------------------------------- Importer::test()

it('imports a single row with Importer::test()', function (): void {
    $this->actingAs(helpersUser());

    $import = ParityPostImporter::test()->import(['title' => 'Desk', 'slug' => 'desk', 'rating' => '4', 'tags_list' => '1|2'])
        ->assertImported()
        ->assertHasNoErrors()
        ->assertHasNoRowFailure();

    expect($import->getRecord()?->only(['title', 'slug', 'rating', 'tags_list']))->toBe(['title' => 'Desk', 'slug' => 'desk', 'rating' => 4, 'tags_list' => ['1', '2']])
        ->and(Post::query()->where('slug', 'desk')->exists())->toBeTrue()
        ->and(Import::query()->count())->toBe(0);
});

it('reports validation errors by field and rule', function (): void {
    $this->actingAs(helpersUser());

    ParityPostImporter::test()->import(['title' => str_repeat('x', 30), 'slug' => 'long', 'rating' => '-1'])
        ->assertHasErrors()
        ->assertHasErrors(['title', 'rating'])
        ->assertHasErrors(['title' => ['max'], 'rating' => ['min']])
        ->assertHasNoErrors(['slug'])
        ->assertHasNoErrors(['rating' => ['integer']]);

    expect(fn () => ParityPostImporter::test()->import(['title' => 'Ok', 'slug' => 'ok'])->assertHasErrors())
        ->toThrow(ExpectationFailedException::class);
});

it('validates each item of a multi-value column with nestedRecursiveRules()', function (): void {
    $this->actingAs(helpersUser());

    ParityPostImporter::test()->import(['title' => 'Tags', 'slug' => 'tags', 'tags_list' => '1|x|0'])
        ->assertHasErrors(['tags_list.1' => ['integer'], 'tags_list.2' => ['min']])
        ->assertHasNoErrors(['tags_list.0']);

    ParityPostImporter::test()->import(['title' => 'Tags', 'slug' => 'tags', 'tags_list' => '1|2|3|4'])
        ->assertHasErrors(['tags_list' => ['max']]);
});

it('detects skipped rows and row failures', function (): void {
    $this->actingAs(helpersUser());

    ParityPostImporter::test(options: ['updateOnly' => true])->import(['title' => 'Nope', 'slug' => 'missing'])->assertSkipped();
    ParityPostImporter::test()->import(['title' => 'Nope', 'slug' => 'forbidden'])->assertHasRowFailure('This slug is reserved.');

    expect(Post::query()->count())->toBe(0);
});

it('uses an explicit column map instead of the column names', function (): void {
    $this->actingAs(helpersUser());

    ParityPostImporter::test(columnMap: ['title' => 'Name', 'slug' => 'Permalink'])
        ->import(['Name' => 'Mapped', 'Permalink' => 'mapped', 'title' => 'ignored'])
        ->assertImported();

    expect(Post::query()->where('slug', 'mapped')->value('title'))->toBe('Mapped');
});

it('runs importer hooks defined in traits', function (): void {
    $this->actingAs(helpersUser());

    ParityPostImporter::test()->import(['title' => 'Hook', 'slug' => 'hook'])->assertImported();
    ParityPostImporter::test()->import(['title' => 'Hook again', 'slug' => 'hook'])->assertImported();

    expect(ParityPostImporter::$traitHooks)->toBe(['beforeSave:hook', 'afterCreate:hook', 'beforeSave:hook']);
});

// ---------------------------------------------------------------- Exporter::test()

it('exports a single record with Exporter::test()', function (): void {
    $user = helpersUser();
    $post = Post::query()->create(['title' => 'Desk', 'slug' => 'desk', 'author_id' => $user->id, 'is_published' => true]);

    expect(PostExporter::test(['title' => 'Title', 'author.name' => 'Author'])->export($post))->toBe(['Desk', 'Ada'])
        ->and(PostExporter::test(['author.name' => 'Author', 'title' => 'Title'])->export($post))->toBe(['Ada', 'Desk'])
        ->and(PostExporter::test()->headers())->not->toContain('Status');
});

// ---------------------------------------------------------------- Fakes

function startFakedImport(AlfaSix\Alfakit\Tests\TestCase $test, string $csv): void
{
    $token = inertiaProps(inertiaGet($test, '/admin/posts'))['page']['token'];
    $key = $test->post('/admin/_alfakit/uploads', ['file' => UploadedFile::fake()->createWithContent('posts.csv', $csv)], ['Accept' => 'application/json'])->json('key');

    inertiaPost($test, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['title' => 'Title', 'slug' => 'Slug']],
    ])->assertRedirect();
}

it('records imports instead of running them with ImportAction::fake()', function (): void {
    $this->actingAs(helpersUser());
    $imports = ImportAction::fake();

    $imports->assertNothingDispatched();

    startFakedImport($this, "Title,Slug\nOne,one\nTwo,two\nThree,three\n");

    $imports->assertDispatched(PostImporter::class)
        ->assertDispatchedTimes(PostImporter::class)
        ->assertDispatched(PostImporter::class, fn (Import $import, array $columnMap, array $options): bool => $import->total_rows === 3 && $columnMap === ['title' => 'Title', 'slug' => 'Slug'])
        ->assertNotDispatched(PostImporter::class, fn (Import $import): bool => $import->total_rows === 1)
        ->assertNotDispatched(ParityPostImporter::class);

    // The rows were not imported.
    expect(Post::query()->count())->toBe(0);

    expect(fn () => $imports->assertNothingDispatched())->toThrow(ExpectationFailedException::class);
});

it('records exports and their records with ExportAction::fake()', function (): void {
    $user = helpersUser();
    $this->actingAs($user);
    $first = Post::query()->create(['title' => 'A', 'slug' => 'a', 'author_id' => $user->id]);
    $second = Post::query()->create(['title' => 'B', 'slug' => 'b', 'author_id' => $user->id]);
    $exports = ExportAction::fake();
    $token = inertiaProps(inertiaGet($this, '/admin/posts'))['page']['token'];

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'export',
        'data' => ['columns' => ['id', 'title']],
    ])->assertRedirect();

    $exports->assertDispatchedTimes(PostExporter::class)
        ->assertDispatched(PostExporter::class, function (Export $export, Builder $query, array $columnMap, array $options, array $formats, ?array $records) use ($first, $second): bool {
            return array_keys($columnMap) === ['id', 'title']
                && collect($records)->sort()->values()->all() === [$first->id, $second->id]
                && $query->getModel() instanceof Post
                && $formats !== [];
        })
        ->assertNotDispatched(PostExporter::class, fn (Export $export, Builder $query, array $columnMap): bool => array_key_exists('status', $columnMap));

    expect(Export::query()->count())->toBe(1)
        ->and(Export::query()->sole()->completed_at)->toBeNull()
        ->and(ExportBulkAction::fake())->toBeInstanceOf(AlfaSix\Alfakit\Actions\Exports\Testing\ExportsFake::class);
});
