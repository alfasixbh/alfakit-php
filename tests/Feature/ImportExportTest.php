<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Actions\ExportBulkAction;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\ImportAction;
use AlfaSix\Alfakit\Actions\Imports\Models\FailedImportRow;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\PostExporter;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\PostImporter;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\ProjectExporter;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\ProjectImporter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Comment;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Tag;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();

    Storage::fake('local');
    config(['alfakit.uploads.temporary_disk' => 'local', 'queue.default' => 'sync']);
    PostImporter::$hooks = [];

    Table::configureUsing(static function (Table $table): void {
        $projects = str_contains(is_object($table->getContext()) ? $table->getContext()::class : '', 'Project');

        $table->headerActions([
            ImportAction::make()->importer($projects ? ProjectImporter::class : PostImporter::class)->maxRows(5)->chunkSize(2),
            ExportAction::make()->exporter($projects ? ProjectExporter::class : PostExporter::class)->chunkSize(2),
        ]);
    });
});

afterEach(function (): void {
    Table::clearConfigurations();
});

function importExportUser(string $email = 'ada@example.com'): User
{
    return User::query()->create(['name' => 'Ada', 'email' => $email, 'password' => 'password']);
}

function uploadCsv(AlfaSix\Alfakit\Tests\TestCase $test, string $contents, string $name = 'posts.csv', string $prefix = '/admin'): string
{
    return $test->post("{$prefix}/_alfakit/uploads", ['file' => UploadedFile::fake()->createWithContent($name, $contents)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');
}

function listToken(AlfaSix\Alfakit\Tests\TestCase $test, string $url = '/admin/posts'): string
{
    return inertiaProps(inertiaGet($test, $url))['page']['token'];
}

function databaseNotification(User $user): array
{
    return (array) $user->notifications()->latest()->firstOrFail()->data;
}

it('guesses the column mapping from the uploaded header row', function (): void {
    $this->actingAs(importExportUser());
    $token = listToken($this);
    $key = uploadCsv($this, "Title,Permalink,Author email,Published,Unrelated\nHello,hello,ada@example.com,yes,x\n");

    $payload = $this->postJson('/admin/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'action:table.import',
        'state' => ['file' => [$key]],
        'path' => 'file',
    ])->assertOk()->json();

    expect($payload['state']['columnMap'])->toBe([
        'title' => 'Title',
        'slug' => 'Permalink',
        'author' => 'Author email',
        'is_published' => 'Published',
        'rating' => null,
    ]);

    // The refreshed upload shows the uploader's file name, not the temporary key.
    expect($payload['components'][0]['files'][0]['name'] ?? null)->toBe('posts.csv');

    $selects = collect(collect($payload['components'])->firstWhere('type', 'Fieldset')['schema'] ?? []);

    expect(array_column($selects->firstWhere('name', 'title')['options'] ?? [], 'value'))
        ->toBe(['Title', 'Permalink', 'Author email', 'Published', 'Unrelated'])
        ->and($selects->firstWhere('name', 'title')['required'] ?? null)->toBeTrue();
});

it('imports valid rows on the queue, records failures and notifies the user', function (): void {
    $user = importExportUser();
    $this->actingAs($user);
    $token = listToken($this);
    Post::query()->create(['title' => 'Old', 'slug' => 'existing', 'author_id' => $user->id]);

    $key = uploadCsv($this, implode("\n", [
        'Title,Slug,Author,Published,Rating',
        'First,first,ada@example.com,yes,4',
        ',missing-title,,no,1',
        'Updated,existing,,true,',
        'Fourth,fourth,nobody@example.com,no,2',
        '=HYPERLINK("x"),bad-rating,,no,-3',
    ]));

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => [
            'file' => [$key],
            'columnMap' => ['title' => 'Title', 'slug' => 'Slug', 'author' => 'Author', 'is_published' => 'Published', 'rating' => 'Rating'],
        ],
    ])->assertRedirect();

    $import = Import::query()->sole();

    expect($import->total_rows)->toBe(5)
        ->and($import->processed_rows)->toBe(5)
        ->and($import->successful_rows)->toBe(2)
        ->and($import->isCompleted())->toBeTrue()
        ->and($import->isOwnedBy($user))->toBeTrue()
        ->and(Post::query()->where('slug', 'first')->first()?->only(['title', 'author_id', 'is_published', 'rating']))
        ->toBe(['title' => 'First', 'author_id' => $user->id, 'is_published' => true, 'rating' => 4])
        ->and(Post::query()->where('slug', 'existing')->value('title'))->toBe('Updated')
        ->and(PostImporter::$hooks)->toContain('afterCreate:first', 'beforeSave:existing')
        ->and(PostImporter::$hooks)->not->toContain('afterCreate:existing')
        ->and(FailedImportRow::query()->count())->toBe(3)
        ->and(FailedImportRow::query()->pluck('validation_error')->implode(' '))
        ->toContain('The Title field is required.', 'No Author matches "nobody@example.com".', 'The Rating field must be at least 0.');

    // The temporary upload is gone once the rows were read.
    expect(Storage::disk('local')->allFiles('alfakit-tmp'))->toBe([]);

    $notification = databaseNotification($user);
    $url = $notification['actions'][0]['url'];

    expect($notification['title'])->toBe('Import completed')
        ->and($notification['body'])->toContain('2 rows were imported.', '3 rows failed.')
        ->and($notification['status'])->toBe('warning');

    $csv = $this->get($url)->assertOk()->streamedContent();

    expect($csv)->toContain('Title,Slug,Author,Published,Rating,Error')
        ->toContain("'=HYPERLINK(")
        ->toContain('nobody@example.com');

    // Links are signed and only work for the user who started the import.
    $this->get(strtok($url, '?'))->assertForbidden();
    $this->actingAs(importExportUser('eve@example.com'))->get($url)->assertNotFound();
});

it('refuses files with more rows than allowed', function (): void {
    $this->actingAs(importExportUser());
    $token = listToken($this);
    $key = uploadCsv($this, "Title,Slug\n".implode("\n", array_map(static fn (int $i): string => "Post {$i},post-{$i}", range(1, 6))));

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['title' => 'Title', 'slug' => 'Slug']],
    ])->assertInvalid(['file']);

    expect(Import::query()->count())->toBe(0)->and(Post::query()->count())->toBe(0);
});

it('requires the mapping of required columns and a CSV file', function (): void {
    $this->actingAs(importExportUser());
    $token = listToken($this);
    $key = uploadCsv($this, "Title,Slug\nHello,hello\n");

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['title' => 'Title']],
    ])->assertInvalid(['columnMap.slug']);

    $image = uploadCsv($this, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='), 'photo.png');

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$image], 'columnMap' => ['title' => 'Title', 'slug' => 'Slug']],
    ])->assertInvalid(['file.0']);
});

it('serves an example CSV through a signed link', function (): void {
    $this->actingAs(importExportUser());
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    $url = URL::temporarySignedRoute('alfakit.admin.internal.imports.example', now()->addHour(), ['importer' => PostImporter::class]);

    expect($this->get($url)->assertOk()->streamedContent())
        ->toContain("title,slug,author,is_published,rating\n")
        ->toContain('"Hello world",hello-world,ada@example.com,yes,');

    $forged = URL::temporarySignedRoute('alfakit.admin.internal.imports.example', now()->addHour(), ['importer' => User::class]);
    $this->get($forged)->assertNotFound();
});

it('exports the filtered table to CSV and XLSX with signed download links', function (): void {
    $user = importExportUser();
    $tag = Tag::query()->create(['name' => 'News']);
    $published = Post::query()->create(['title' => '=SUM(A1)', 'slug' => 'a', 'author_id' => $user->id, 'is_published' => true]);
    $published->tags()->attach($tag);
    Comment::query()->create(['post_id' => $published->id, 'author_name' => 'Bob', 'body' => 'Hi']);
    Post::query()->create(['title' => 'Second', 'slug' => 'b', 'author_id' => $user->id, 'is_published' => true]);
    Post::query()->create(['title' => 'Draft', 'slug' => 'c', 'author_id' => $user->id, 'is_published' => false]);

    $this->actingAs($user);
    $token = listToken($this);

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'export',
        'tableQuery' => 'filters[is_published][value]=1&sort=title&direction=asc&record=999',
        'data' => ['columns' => ['title', 'author.name', 'comments_count', 'tags.name', 'id']],
    ])->assertRedirect();

    $export = Export::query()->sole();

    expect($export->total_rows)->toBe(2)
        ->and($export->successful_rows)->toBe(2)
        ->and($export->isCompleted())->toBeTrue();

    $notification = databaseNotification($user);
    $links = collect($notification['actions'])->pluck('url', 'name');

    expect($notification['title'])->toBe('Export completed')
        ->and($links->keys()->all())->toBe(['downloadCsv', 'downloadXlsx']);

    $csv = $this->get($links['downloadCsv'])->assertOk()->streamedContent();

    // Exporter column order, the table's filter and sort, and formulas neutralised.
    expect(str_replace("\xEF\xBB\xBF", '', (string) $csv))->toBe(implode("\n", [
        'ID,Title,Author,"Comments count",Tags',
        "{$published->id},'=SUM(A1),Ada,1,News",
        ($published->id + 1).',Second,Ada,0,',
    ])."\n");

    $xlsx = Storage::disk('local')->path($export->getFilePath(AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat::Xlsx));
    $zip = new ZipArchive;
    expect($zip->open($xlsx))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheet)->toContain('<t xml:space="preserve">=SUM(A1)</t>')->toContain('<v>1</v>');

    $this->get(strtok($links['downloadCsv'], '?').'?format=csv')->assertForbidden();
    $this->actingAs(importExportUser('eve@example.com'))->get($links['downloadCsv'])->assertNotFound();
});

it('exports the selected records with the bulk action', function (): void {
    $user = importExportUser();
    $posts = collect(['One', 'Two', 'Three'])->map(fn (string $title, int $i) => Post::query()->create(['title' => $title, 'slug' => "s{$i}", 'author_id' => $user->id]));
    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    $action = ExportBulkAction::make()->exporter(PostExporter::class)->columnMapping(false)->formats([AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat::Csv]);
    $action->records(new Illuminate\Database\Eloquent\Collection([$posts[2], $posts[0]]));
    $action->execute();

    $export = Export::query()->sole();
    $csv = Storage::disk('local')->get($export->getFilePath(AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat::Csv));

    expect($export->total_rows)->toBe(2)
        ->and($export->hasFile(AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat::Xlsx))->toBeFalse()
        ->and((string) $csv)->toContain("{$posts[2]->id},Three,Ada,0,0,\n{$posts[0]->id},One,Ada,0,0,")
        ->not->toContain('Status');
});

it('refuses exports above the row limit', function (): void {
    $user = importExportUser();
    Post::query()->create(['title' => 'One', 'slug' => 'one']);
    Post::query()->create(['title' => 'Two', 'slug' => 'two']);
    $this->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    $action = ExportAction::make()->exporter(PostExporter::class)->columnMapping(false)->maxRows(1);

    expect(fn () => $action->execute())->toThrow(AlfaSix\Alfakit\Actions\Exceptions\Halt::class)
        ->and(Export::query()->count())->toBe(0);
});

it('keeps imports and exports inside the current tenant', function (): void {
    $user = TenantUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);
    $alpha = Team::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    $other = Team::query()->create(['name' => 'Other', 'slug' => 'other']);
    $user->teams()->attach($alpha->id);
    Project::query()->create(['team_id' => $alpha->id, 'name' => 'Alpha project']);
    $foreign = Project::query()->create(['team_id' => $other->id, 'name' => 'Other project']);

    $this->actingAs($user);
    $token = listToken($this, '/app/alpha/projects');
    $key = uploadCsv($this, "id,name\n,New project\n{$foreign->id},Hijacked\n", 'projects.csv', '/app/alpha');

    inertiaPost($this, '/app/alpha/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['id' => 'id', 'name' => 'name']],
    ])->assertRedirect();

    $import = Import::query()->sole();

    expect(Project::query()->where('name', 'New project')->value('team_id'))->toBe($alpha->id)
        ->and($foreign->refresh()->name)->toBe('Other project')
        ->and($import->successful_rows)->toBe(1)
        ->and($import->tenant_id)->toBe((string) $alpha->id);

    inertiaPost($this, '/app/alpha/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'export',
        'data' => ['columns' => ['name']],
    ])->assertRedirect();

    $export = Export::query()->sole();

    expect((string) Storage::disk('local')->get($export->getFilePath(AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat::Csv)))
        ->toContain('Alpha project', 'New project')
        ->not->toContain('Other project');

    // The tenant does not leak into later code running in the same process.
    expect(alfakit()->getTenant()?->getKey())->not->toBe($other->id);
});

it('hides the actions from users without permission', function (): void {
    $this->actingAs(importExportUser());
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    Illuminate\Support\Facades\Gate::policy(Post::class, ImportExportDenyPolicy::class);

    expect(ImportAction::make()->importer(PostImporter::class)->isVisible())->toBeFalse()
        ->and(ExportAction::make()->exporter(PostExporter::class)->isVisible())->toBeFalse();
});

class ImportExportDenyPolicy
{
    public function create(): bool
    {
        return false;
    }

    public function viewAny(): bool
    {
        return false;
    }
}

it('queues one job per chunk and completes when the last chunk finishes', function (): void {
    config(['queue.default' => 'database']);
    Illuminate\Support\Facades\Queue::fake();

    $user = importExportUser();
    $this->actingAs($user);
    $token = listToken($this);
    $key = uploadCsv($this, "Title,Slug\nOne,one\nTwo,two\nThree,three\n");

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['title' => 'Title', 'slug' => 'Slug']],
    ])->assertRedirect();

    $import = Import::query()->sole();
    $jobs = Illuminate\Support\Facades\Queue::pushed(AlfaSix\Alfakit\Actions\Imports\Jobs\ImportCsv::class);

    expect($jobs)->toHaveCount(2)
        ->and($import->isCompleted())->toBeFalse()
        ->and(session('_flash.new'))->not->toBeNull()
        ->and(collect(AlfaSix\Alfakit\Notifications\Notification::getFlashed())->pluck('title')->all())->toContain('Import started');

    // Workers run outside any panel request.
    alfakit()->resetRequestState();

    $jobs->each(fn (AlfaSix\Alfakit\Actions\Imports\Jobs\ImportCsv $job) => $job->handle());

    expect($import->refresh()->isCompleted())->toBeTrue()
        ->and(Post::query()->pluck('slug')->all())->toBe(['one', 'two', 'three'])
        ->and($user->notifications()->count())->toBe(1)
        ->and(alfakit()->getCurrentPanel())->toBeNull();
});

it('generates importers and exporters from the model table', function (): void {
    $directory = sys_get_temp_dir().'/alfakit-generators-'.uniqid();
    config(['alfakit.file_generation.path' => $directory, 'alfakit.file_generation.namespace' => 'App\\Alfakit']);

    $this->artisan('make:alfakit-importer', ['model' => Post::class, '--generate' => true])->assertSuccessful();
    $this->artisan('alfakit:make-exporter', ['model' => Post::class, '--generate' => true])->assertSuccessful();

    $importer = (string) file_get_contents("{$directory}/Admin/Imports/PostImporter.php");
    $exporter = (string) file_get_contents("{$directory}/Admin/Exports/PostExporter.php");

    expect($importer)->toContain('namespace App\\Alfakit\\Admin\\Imports;')
        ->toContain('use AlfaSix\\Alfakit\\Tests\\Fixtures\\Models\\Post;')
        ->toContain("ImportColumn::make('title')\n                ->requiredMapping()\n                ->rules(['required', 'max:255']),")
        // Resolved by the title the exporter writes (author.name) or the key, so exports import again.
        ->toContain("ImportColumn::make('author')\n                ->relationship(resolveUsing: ['name', 'id']),")
        ->toContain("ImportColumn::make('is_published')\n                ->boolean()\n                ->rules(['boolean']),")
        ->toContain("ImportColumn::make('rating')\n                ->integer()\n                ->rules(['integer']),")
        ->not->toContain("make('created_at')")
        // Array casts round-trip as JSON (SQLite reports the json column as text).
        ->toContain("ImportColumn::make('meta')\n                ->castStateUsing(fn (mixed \$state): mixed => is_string(\$state) && json_validate(\$state) ? json_decode(\$state, true) : \$state),")
        ->and($exporter)->toContain("ExportColumn::make('meta')\n                ->listAsJson(),")
        ->and($exporter)->toContain("ExportColumn::make('id')\n                ->label('ID'),")
        ->toContain("ExportColumn::make('author.name'),")
        ->toContain("ExportColumn::make('category.name'),")
        ->toContain("ExportColumn::make('created_at'),");

    foreach ([$importer, $exporter] as $code) {
        expect(fn () => token_get_all($code, TOKEN_PARSE))->not->toThrow(ParseError::class);
    }

    Illuminate\Support\Facades\File::deleteDirectory($directory);
});
