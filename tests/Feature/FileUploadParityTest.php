<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema as DbSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
 * FileUpload options ported from Filament: parallel uploads, original file names kept in
 * another attribute, path tampering hooks, layout positions, open/download URLs, file
 * information and MIME type maps.
 */

class FuDocument extends Model
{
    protected $table = 'fu_documents';

    protected $guarded = [];

    protected $casts = ['attachments' => 'array', 'attachment_file_names' => 'array', 'lines' => 'array'];
}

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.default_filesystem_disk' => 'public']);

    DbSchema::create('fu_documents', function (Blueprint $table): void {
        $table->id();
        $table->json('attachments')->nullable();
        $table->json('attachment_file_names')->nullable();
        $table->string('avatar')->nullable();
        $table->string('avatar_name')->nullable();
        $table->json('lines')->nullable();
        $table->timestamps();
    });
});

function fuActAs(): void
{
    $user = User::query()->create(['name' => 'Files', 'email' => 'files-'.uniqid().'@example.com', 'password' => 'password']);
    test()->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
}

function fuUpload(UploadedFile $file): string
{
    $key = (string) test()->post('/admin/_alfakit/uploads', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');

    // The request leaves no current panel behind: forms below resolve the admin panel's.
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    return $key;
}

it('serialises the layout, parallel upload and MIME map options', function (): void {
    $field = FileUpload::make('attachments')->multiple()
        ->maxParallelUploads(3)
        ->appendFiles(false)
        ->panelLayout('integrated')
        ->panelAspectRatio('2:1')
        ->imagePreviewHeight('250px')
        ->loadingIndicatorPosition('left')
        ->removeUploadedFileButtonPosition('right')
        ->uploadButtonPosition('center')
        ->mimeTypeMap(['.SKP' => 'application/vnd.sketchup.skp']);

    $props = schema([$field])->fill()->toSchema()[0];

    expect($props['maxParallelUploads'])->toBe(3)
        ->and($props['appendFiles'])->toBeFalse()
        ->and($props['panelLayout'])->toBe('integrated')
        ->and($props['panelAspectRatio'])->toBe('2:1')
        ->and($props['imagePreviewHeight'])->toBe(250)
        ->and($props['positions'])->toBe(['removeButton' => 'end', 'progressIndicator' => 'start', 'uploadButton' => 'center'])
        ->and($props['mimeTypeMap'])->toBe(['skp' => 'application/vnd.sketchup.skp']);

    // Defaults stay out of the payload; closures are evaluated.
    $plain = schema([FileUpload::make('file')->maxParallelUploads(fn (): int => 0)->panelAspectRatio('nonsense')])->fill()->toSchema()[0];

    expect($plain['maxParallelUploads'])->toBe(1)
        ->and($plain)->not->toHaveKeys(['appendFiles', 'positions', 'mimeTypeMap', 'panelAspectRatio']);
});

it('stores the original names of multiple uploads in another attribute', function (): void {
    fuActAs();
    $report = fuUpload(UploadedFile::fake()->create('Quarterly report.pdf', 4, 'application/pdf'));
    $photo = fuUpload(UploadedFile::fake()->image('Team photo.png'));

    $field = fn (): FileUpload => FileUpload::make('attachments')->multiple()->directory('docs')->storeFileNamesIn('attachment_file_names');

    $form = schema([$field()], model: FuDocument::class)->fill();
    $form->rawState(['attachments' => [$report, $photo]]);
    $data = $form->validate();

    expect($data['attachments'])->toHaveCount(2)
        ->and(array_values($data['attachment_file_names']))->toBe(['Quarterly report.pdf', 'Team photo.png'])
        ->and(array_keys($data['attachment_file_names']))->toBe($data['attachments']);

    $document = FuDocument::query()->create($data);

    // The edit form shows the original names and keeps them for the files that stay.
    $edit = schema([$field()], record: $document, operation: 'edit')->fill();
    $files = $edit->toSchema()[0]['files'];

    expect(array_column($files, 'name'))->toBe(['Quarterly report.pdf', 'Team photo.png']);

    $edit->rawState(['attachments' => [$document->attachments[1]]]);
    $updated = $edit->validate();

    expect($updated['attachment_file_names'])->toBe([$document->attachments[1] => 'Team photo.png']);
});

it('stores the original name of a single upload, also inside repeater items', function (): void {
    fuActAs();
    $key = fuUpload(UploadedFile::fake()->image('Me at the beach.png'));

    $form = schema([FileUpload::make('avatar')->storeFileNamesIn('avatar_name')], model: FuDocument::class)->fill();
    $form->rawState(['avatar' => [$key]]);
    $data = $form->validate();

    expect($data['avatar_name'])->toBe('Me at the beach.png')
        ->and($data['avatar'])->toEndWith('.png');

    $line = fuUpload(UploadedFile::fake()->create('Invoice 7.pdf', 2, 'application/pdf'));
    $repeater = Repeater::make('lines')->schema([FileUpload::make('file')->storeFileNamesIn('file_name')]);
    $form = schema([$repeater], model: FuDocument::class)->fill();
    $form->rawState(['lines' => [['file' => [$line]]]]);
    $lines = $form->validate()['lines'];

    expect(array_values($lines)[0]['file_name'])->toBe('Invoice 7.pdf');
});

it('names stored files with a Filament-style getUploadedFileNameForStorageUsing(fn ($file)) closure', function (): void {
    fuActAs();

    $form = schema([FileUpload::make('avatar')->directory('avatars')
        ->getUploadedFileNameForStorageUsing(fn ($file): string => 'qa-'.$file->getClientOriginalName())], model: FuDocument::class)->fill();
    $form->rawState(['avatar' => [fuUpload(UploadedFile::fake()->image('Beach.png'))]]);

    expect($form->validate()['avatar'])->toBe('avatars/qa-Beach.png');

    $form = schema([FileUpload::make('avatar')->directory('avatars')
        ->getUploadedFileNameForStorageUsing(fn (string $originalName, string $extension): string => "named.{$extension}")], model: FuDocument::class)->fill();
    $form->rawState(['avatar' => [fuUpload(UploadedFile::fake()->image('Other.png'))]]);

    expect($form->validate()['avatar'])->toBe('avatars/named.png');
});

it('rejects tampered paths unless allowFilePathUsing allows them', function (): void {
    fuActAs();
    Storage::disk('public')->put('library/terms.pdf', 'terms');
    Storage::disk('public')->put('private/secret.pdf', 'secret');

    $field = fn (): FileUpload => FileUpload::make('attachment')
        ->preventFilePathTampering(allowFilePathUsing: fn (string $file): bool => str_starts_with($file, 'library/'))
        ->validationMessages(['tampered' => 'Pick a file from the library.']);

    $allowed = schema([$field()])->fill();
    $allowed->rawState(['attachment' => ['library/terms.pdf']]);

    expect($allowed->validate()['attachment'])->toBe('library/terms.pdf');

    $tampered = schema([$field()])->fill();
    $tampered->rawState(['attachment' => ['private/secret.pdf']]);

    try {
        $tampered->validate();
        $this->fail('expected a validation error');
    } catch (ValidationException $exception) {
        expect($exception->errors()['attachment.0'])->toBe(['Pick a file from the library.']);
    }

    // Opting out accepts any stored path.
    $open = schema([FileUpload::make('attachment')->preventFilePathTampering(false)])->fill();
    $open->rawState(['attachment' => ['private/secret.pdf']]);

    expect($open->validate()['attachment'])->toBe('private/secret.pdf');
});

it('sends separate open and download URLs and the stored file information', function (): void {
    Storage::disk('public')->put('docs/a.pdf', str_repeat('x', 2048));

    $field = FileUpload::make('attachments')->multiple()->openable()->downloadable()
        ->getOpenableFileUrlUsing(fn (string $file): string => "https://cdn.test/view/{$file}")
        ->getDownloadableFileUrlUsing(fn (string $file, ?Model $record): ?string => $file === 'docs/a.pdf' ? "https://cdn.test/download/{$file}" : null);

    $files = schema([$field])->fill(['attachments' => ['docs/a.pdf', 'docs/gone.pdf']])->toSchema()[0]['files'];

    expect($files[0])->toMatchArray([
        'path' => 'docs/a.pdf',
        'size' => 2048,
        'openUrl' => 'https://cdn.test/view/docs/a.pdf',
        'downloadUrl' => 'https://cdn.test/download/docs/a.pdf',
    ])->and($files[1]['missing'])->toBeTrue()
        ->and($files[1])->not->toHaveKey('downloadUrl')
        ->and($files[1]['openUrl'])->toBe('https://cdn.test/view/docs/gone.pdf');

    $quiet = schema([FileUpload::make('attachments')->multiple()->fetchFileInformation(false)])
        ->fill(['attachments' => ['docs/a.pdf', 'docs/gone.pdf']])->toSchema()[0]['files'];

    expect($quiet[0])->not->toHaveKeys(['size', 'missing'])
        ->and($quiet[1])->not->toHaveKey('missing');
});

it('maps extensions the server cannot detect to MIME types', function (): void {
    fuActAs();
    // A real file, so its type is detected from the content (fakes report one from the name).
    $path = tempnam(sys_get_temp_dir(), 'skp');
    // Fixed bytes: random ones are sometimes detected as an image (e.g. image/x-tga).
    file_put_contents($path, str_repeat("\x00\x01\x02\x03\xfe\xff\x80\x90", 9));
    $key = fuUpload(new UploadedFile($path, 'house.skp', null, null, true));

    $without = schema([FileUpload::make('design')->acceptedFileTypes(['application/vnd.sketchup.skp'])])->fill();
    $without->rawState(['design' => [$key]]);

    expect(fn () => $without->validate())->toThrow(ValidationException::class);

    $mapped = schema([FileUpload::make('design')->directory('designs')
        ->acceptedFileTypes(['application/vnd.sketchup.skp'])
        ->mimeTypeMap(['skp' => 'application/vnd.sketchup.skp'])])->fill();
    $mapped->rawState(['design' => [$key]]);
    $path = $mapped->validate()['design'];

    expect($path)->toStartWith('designs/')->toEndWith('.skp')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();

    // A real image named `.skp` keeps its detected type: the map only replaces generic ones.
    $png = fuUpload(UploadedFile::fake()->image('trick.png'));
    $form = schema([FileUpload::make('design')->acceptedFileTypes(['application/vnd.sketchup.skp'])->mimeTypeMap(['png' => 'application/vnd.sketchup.skp'])])->fill();
    $form->rawState(['design' => [$png]]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);
});

it('treats a local disk without a public url as private and signs its file links', function (): void {
    config()->set('filesystems.disks.privatelocal', ['driver' => 'local', 'root' => storage_path('app/private-test'), 'serve' => true]);
    config()->set('filesystems.disks.publiclocal', ['driver' => 'local', 'root' => storage_path('app/public-test'), 'url' => 'http://localhost/storage']);
    config()->set('filesystems.disks.explicit', ['driver' => 'local', 'root' => storage_path('app/explicit-test'), 'visibility' => 'public']);

    expect(FileUpload::make('a')->disk('privatelocal')->getVisibility())->toBe('private')
        ->and(FileUpload::make('b')->disk('publiclocal')->getVisibility())->toBe('public')
        ->and(FileUpload::make('c')->disk('explicit')->getVisibility())->toBe('public');

    // Laravel's default `local` disk (storage/app/private, `serve`) gets signed links, never
    // the plain /storage path that answers 403.
    $url = FileUpload::make('a')->disk('local')->getFileUrl('posts/x.png');

    expect(FileUpload::make('a')->disk('local')->getVisibility())->toBe(config('filesystems.disks.local.url') ? 'public' : 'private');

    if (config('filesystems.disks.local.serve')) {
        expect((string) $url)->toContain('signature=');
    } else {
        expect($url === null || ! str_contains($url, '/storage/posts'))->toBeTrue();
    }
});

it('shows images on a private local disk through signed urls in columns and entries', function (): void {
    config()->set('filesystems.disks.privatelocal', ['driver' => 'local', 'root' => storage_path('app/private-test'), 'serve' => true]);
    config()->set('filesystems.disks.publiclocal', ['driver' => 'local', 'root' => storage_path('app/public-test'), 'url' => 'http://localhost/storage']);
    Storage::disk('privatelocal')->buildTemporaryUrlsUsing(fn (string $path): string => "http://localhost/signed/{$path}?signature=x");

    // The same disk FileUpload stored the file on: never the plain /storage path.
    $column = AlfaSix\Alfakit\Tables\Columns\ImageColumn::make('image')->disk('privatelocal');
    $entry = AlfaSix\Alfakit\Infolists\Components\ImageEntry::make('image')->disk('privatelocal');

    expect((string) $column->getImageUrl('posts/x.png'))->toContain('signature=')
        ->and((string) $entry->getImageUrl('posts/x.png'))->toContain('signature=')
        ->and($column->disk('publiclocal')->getImageUrl('posts/x.png'))->toBe('http://localhost/storage/posts/x.png')
        ->and($column->disk('privatelocal')->visibility('public')->getImageUrl('posts/x.png'))->not->toContain('signature=');
});
