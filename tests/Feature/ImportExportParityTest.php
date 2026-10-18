<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\ExportAction;
use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\Jobs\ExportCsv;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxStyle;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxWriter;
use AlfaSix\Alfakit\Actions\ImportAction;
use AlfaSix\Alfakit\Actions\Imports\CsvReader;
use AlfaSix\Alfakit\Actions\Imports\ImportNotifications;
use AlfaSix\Alfakit\Actions\Imports\Jobs\ImportCsv;
use AlfaSix\Alfakit\Actions\Imports\Models\FailedImportRow;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\BroadcastNotification;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\ParityExporter;
use AlfaSix\Alfakit\Tests\Fixtures\ImportExport\ParityImporter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Storage;

/*
 * Nice-to-have import/export options, notification helpers and widget options.
 */

beforeEach(function (): void {
    (require dirname(__DIR__, 2).'/database/migrations/0001_01_01_000100_create_alfakit_import_export_tables.php')->up();

    Storage::fake('local');
    config(['alfakit.uploads.temporary_disk' => 'local', 'queue.default' => 'sync']);
    ParityImporter::$customDownloader = false;

    Table::configureUsing(static function (Table $table): void {
        $table->headerActions([
            ImportAction::make()->importer(ParityImporter::class)->headerOffset(1)->fileRules(['max:1']),
            ExportAction::make()->exporter(ParityExporter::class)->columnMappingColumns(3)->enableVisibleTableColumnsByDefault(),
        ]);
    });
});

afterEach(function (): void {
    Table::clearConfigurations();
    Stat::clearConfigurations();
});

function parityImportUser(): User
{
    return User::query()->create(['name' => 'Ada', 'email' => 'ada'.uniqid().'@example.com', 'password' => 'password']);
}

function parityUpload(mixed $test, string $contents): string
{
    return $test->post('/admin/_alfakit/uploads', ['file' => UploadedFile::fake()->createWithContent('posts.csv', $contents)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');
}

function parityToken(mixed $test): string
{
    return inertiaProps(inertiaGet($test, '/admin/posts'))['page']['token'];
}

// ---------------------------------------------------------------- Import

it('reads the header below headerOffset() rows', function (): void {
    $reader = CsvReader::fromString("Exported on Monday\nTitle,Slug\nHello,hello\n", headerOffset: 1);

    expect($reader->getHeaders())->toBe(['Title', 'Slug'])
        ->and(iterator_to_array($reader->getRecords(), false))->toBe([['Title' => 'Hello', 'Slug' => 'hello']]);
});

it('imports with a header offset, keeps sensitive values out of failed rows and customises the notification', function (): void {
    $user = parityImportUser();
    $this->actingAs($user);
    $token = parityToken($this);
    $key = parityUpload($this, "Report\nTitle,Slug,Secret\nFirst,first,s3cret\n,missing,hidden\n");

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['title' => 'Title', 'slug' => 'Slug', 'content' => 'Secret']],
    ])->assertRedirect();

    $import = Import::query()->sole();
    $failed = FailedImportRow::query()->sole();
    $notification = (array) $user->notifications()->latest()->firstOrFail()->data;

    expect($import->successful_rows)->toBe(1)
        ->and(Post::query()->where('slug', 'first')->value('content'))->toBe('s3cret')
        ->and($failed->data)->toBe(['Title' => '', 'Slug' => 'missing'])
        ->and($notification['title'])->toBe('Posts imported')
        ->and($notification['icon'])->toBe('rocket');
});

it('rejects files that fail fileRules()', function (): void {
    $this->actingAs(parityImportUser());
    $token = parityToken($this);
    $key = parityUpload($this, "Report\nTitle,Slug\n".str_repeat("Row,row\n", 300));

    $this->postJson('/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'import',
        'data' => ['file' => [$key], 'columnMap' => ['title' => 'Title', 'slug' => 'Slug']],
    ])->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Import::query()->count())->toBe(0);
});

it('serves failed rows through getFailedRowsDownloader()', function (): void {
    $user = parityImportUser();
    $this->actingAs($user);
    ParityImporter::$customDownloader = true;

    $import = new Import;
    $import->setOwner($user, null, alfakit()->getPanel('admin'));
    $import->fill(['importer' => ParityImporter::class, 'file_name' => 'posts.csv', 'total_rows' => 1])->save();
    FailedImportRow::query()->create(['import_id' => $import->getKey(), 'data' => ['Title' => 'x'], 'validation_error' => 'Bad']);

    $this->get((string) ImportNotifications::failedRowsUrl($import))->assertOk()->assertSee('custom 1');
});

it('passes the importer job settings to the chunk jobs', function (): void {
    $import = new Import;
    $import->fill(['importer' => ParityImporter::class, 'file_name' => 'posts.csv', 'total_rows' => 0])->save();

    $job = new ImportCsv($import, [], []);

    expect($job->tags())->toBe(["import{$import->getKey()}", 'posts'])
        ->and($job->backoff())->toBe([5, 10])
        ->and($job->retryUntil())->toBeNull()
        ->and($job->middleware())->toBe([]);
});

// ---------------------------------------------------------------- Export

it('offers the visible table columns by default with renameable labels', function (): void {
    $this->actingAs(parityImportUser());
    $token = parityToken($this);

    $modal = $this->getJson('/admin/_alfakit/actions/modal?'.http_build_query(['token' => $token, 'component' => 'table', 'action' => 'export']))->assertOk()->json();
    $columns = collect($modal['form']['components'])->firstWhere('name', 'columns');

    // The posts table shows `title` among others; `id` and `rating` are not table columns.
    expect($modal['form']['state']['columns'])->toContain('title')
        ->and($modal['form']['state']['columns'])->not->toContain('status')
        ->and(json_encode($columns))->toContain('"gridColumns":3');
});

it('exports with renamed headers, a styled XLSX, a custom notification and downloader', function (): void {
    if (! XlsxWriter::isSupported()) {
        $this->markTestSkipped('The zip extension is missing.');
    }

    $user = parityImportUser();
    Post::query()->create(['title' => 'First', 'slug' => 'first', 'rating' => 3]);
    $this->actingAs($user);
    $token = parityToken($this);

    inertiaPost($this, '/admin/_alfakit/actions', [
        'token' => $token,
        'component' => 'table',
        'action' => 'export',
        'data' => ['columns' => ['id', 'title', 'rating'], 'columnLabels' => ['title' => 'Headline', 'rating' => '  ']],
    ])->assertRedirect();

    $export = Export::query()->sole();
    $notification = (array) $user->notifications()->latest()->firstOrFail()->data;
    $links = collect($notification['actions'])->pluck('url', 'name');

    expect($notification['title'])->toBe('Your posts are ready')
        ->and($notification['icon'])->toBe('package');

    $this->get($links['downloadCsv'])->assertOk()->assertSee('custom csv '.$export->getKey());

    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($export->getFilePath(ExportFormat::Xlsx))))->toBeTrue();
    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $styles = (string) $zip->getFromName('xl/styles.xml');
    $workbook = (string) $zip->getFromName('xl/workbook.xml');
    $zip->close();

    expect($sheet)->toContain('Headline', 'Rating', 'state="frozen"', '<col min="2" max="2" width="30" customWidth="1"/>')
        ->and(simplexml_load_string($sheet))->not->toBeFalse()
        ->and(simplexml_load_string($styles))->not->toBeFalse()
        ->and($styles)->toContain('Consolas', 'FFFFEE00', 'formatCode="0.00"')
        ->and($workbook)->toContain('name="Posts"');
});

it('passes the exporter job settings to the chunk jobs', function (): void {
    $export = new Export;
    $export->fill(['exporter' => ParityExporter::class, 'file_disk' => 'local', 'file_name' => 'export', 'total_rows' => 0])->save();

    $job = new ExportCsv($export, 0, [], ['id' => 'ID']);

    expect($job->tags())->toBe(["export{$export->getKey()}"])
        ->and($job->retryUntil())->toBeNull()
        ->and($job->backoff())->toBeNull();
});

it('merges XLSX styles and shares equal ones', function (): void {
    $base = (new XlsxStyle)->setFontName('Consolas')->setFontSize(12);
    $merged = $base->mergeWith((new XlsxStyle)->setFontBold());

    expect($merged->fontName)->toBe('Consolas')
        ->and($merged->bold)->toBeTrue()
        ->and($base->bold)->toBeNull()
        ->and((new XlsxStyle)->setFontColor('#zz0000')->fontColor)->toBeNull()
        ->and($merged->getKey())->toBe((clone $merged)->getKey());
});

// ---------------------------------------------------------------- Notifications

it('serialises markAsUnread() notification actions', function (): void {
    $action = NotificationAction::make('unread')->markAsUnread()->toArray();

    expect($action['markAsUnread'])->toBeTrue()
        ->and(NotificationAction::fromArray($action)->toArray()['markAsUnread'])->toBeTrue();
});

it('marks a database notification as unread through the read endpoint', function (): void {
    $user = parityImportUser();
    Notification::make()->title('Hello')->sendToDatabase($user);
    $record = $user->notifications()->firstOrFail();
    $record->markAsRead();
    $this->actingAs($user);

    $this->postJson("/admin/_alfakit/notifications/{$record->getKey()}/read", ['unread' => true])
        ->assertOk()
        ->assertJson(['unreadCount' => 1]);

    expect($record->fresh()?->read_at)->toBeNull();
});

it('gives custom Laravel notifications the database and broadcast messages', function (): void {
    $notification = Notification::make()->title('Saved')->success();

    expect($notification->getDatabaseMessage())->toMatchArray(['title' => 'Saved', 'status' => 'success', 'format' => 'alfakit'])
        ->and($notification->getBroadcastMessage())->toBeInstanceOf(BroadcastMessage::class)
        ->and($notification->getBroadcastMessage()->data['title'])->toBe('Saved')
        ->and(BroadcastNotification::TYPE)->toBe('alfakit.notification');
});

// ---------------------------------------------------------------- Widgets

it('shows a stat placeholder for empty values, also configured globally', function (): void {
    Stat::configureUsing(static fn (Stat $stat) => $stat->placeholder('—'));

    expect(Stat::make('Revenue', null)->toSchema()['placeholder'])->toBe('—')
        ->and(Stat::make('Revenue', '')->toSchema()['placeholder'])->toBe('—')
        ->and(Stat::make('Revenue', 0)->toSchema())->not->toHaveKey('placeholder')
        ->and(Stat::make('Revenue', null)->placeholder(fn (): string => 'n/a')->toSchema()['placeholder'])->toBe('n/a');
});
