<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.default_filesystem_disk' => 'public']);
});

function uploader(string $email = 'uploader@example.com'): User
{
    return User::query()->create(['name' => 'Uploader', 'email' => $email, 'password' => 'password']);
}

function uploadFile(UploadedFile $file): Illuminate\Testing\TestResponse
{
    return test()->post('/admin/_alfakit/uploads', ['file' => $file], ['Accept' => 'application/json']);
}

function actAsPanelUser(User $user): void
{
    test()->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
}

it('derives the stored extension from the detected type, not the client name', function (): void {
    $this->actingAs(uploader());

    // A real PNG named like a script: fake files report a type from their name instead.
    $png = UploadedFile::fake()->image('photo.png', 10, 10);
    $file = new UploadedFile($png->getRealPath(), 'photo.php', 'application/x-php', null, true);

    $key = uploadFile($file)->assertOk()->json('key');

    expect($key)->toStartWith('tmp:')->toEndWith('.png')
        ->and(TemporaryUpload::nameFromKey($key))->not->toBeNull();
});

it('refuses file types that browsers would execute', function (): void {
    $this->actingAs(uploader());

    uploadFile(UploadedFile::fake()->createWithContent('page.html', '<html><script>alert(1)</script></html>'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');
});

it('rejects malformed temporary keys', function (string $key): void {
    expect(TemporaryUpload::nameFromKey($key))->toBeNull()
        ->and(TemporaryUpload::find($key))->toBeNull();
})->with(['tmp:../../.env', 'tmp:01HZX/../secret', 'tmp:', 'tmp:not-a-ulid.png', 'storage/private.pdf']);

it('only lets the uploader delete a temporary file', function (): void {
    $owner = uploader();
    $this->actingAs($owner);
    $key = uploadFile(UploadedFile::fake()->image('a.png'))->json('key');
    $path = TemporaryUpload::directory().'/'.TemporaryUpload::nameFromKey($key);

    $this->actingAs(uploader('other@example.com'))
        ->delete('/admin/_alfakit/uploads', ['key' => $key], ['Accept' => 'application/json'])->assertOk();
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    $this->actingAs($owner)
        ->delete('/admin/_alfakit/uploads', ['key' => $key], ['Accept' => 'application/json'])->assertOk();
    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

it('does not attach another user\'s temporary upload', function (): void {
    $this->actingAs(uploader());
    $key = uploadFile(UploadedFile::fake()->image('a.png'))->json('key');

    actAsPanelUser(uploader('other@example.com'));
    $form = schema([FileUpload::make('avatar')])->fill();
    $form->rawState(['avatar' => [$key]]);

    expect(fn () => $form->validate())->toThrow(ValidationException::class);
});

it('validates accepted types and size on the server', function (): void {
    $user = uploader();
    $this->actingAs($user);
    $pdf = uploadFile(UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'))->json('key');
    $image = uploadFile(UploadedFile::fake()->image('a.png')->size(200))->json('key');

    actAsPanelUser($user);

    $imageOnly = schema([FileUpload::make('avatar')->image()])->fill();
    $imageOnly->rawState(['avatar' => [$pdf]]);
    expect(fn () => $imageOnly->validate())->toThrow(ValidationException::class);

    $small = schema([FileUpload::make('avatar')->maxSize(100)])->fill();
    $small->rawState(['avatar' => [$image]]);
    expect(fn () => $small->validate())->toThrow(ValidationException::class);

    $ok = schema([FileUpload::make('avatar')->image()->directory('avatars')])->fill();
    $ok->rawState(['avatar' => [$image]]);
    $state = $ok->validate();

    expect($state['avatar'])->toStartWith('avatars/')->toEndWith('.png')
        ->and(Storage::disk('public')->exists($state['avatar']))->toBeTrue();
});

it('does not accept stored paths the field was not filled with', function (): void {
    actAsPanelUser(uploader());
    Storage::disk('public')->put('invoices/someone-else.pdf', 'secret');

    $form = schema([FileUpload::make('attachment')])->fill();
    $form->rawState(['attachment' => ['invoices/someone-else.pdf']]);
    expect(fn () => $form->validate())->toThrow(ValidationException::class);

    $kept = schema([FileUpload::make('attachment')])->fill(['attachment' => 'invoices/mine.pdf']);
    $kept->rawState(['attachment' => ['invoices/mine.pdf']]);
    expect($kept->validate()['attachment'])->toBe('invoices/mine.pdf');
});
