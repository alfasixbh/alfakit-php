<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\MarkdownEditor;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.default_filesystem_disk' => 'public']);
});

function richEditorUser(string $email = 'editor@example.com'): User
{
    return User::query()->create(['name' => 'Editor', 'email' => $email, 'password' => 'password']);
}

function richEditorUpload(UploadedFile $file): string
{
    return (string) test()->post('/admin/_alfakit/uploads', ['file' => $file], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('key');
}

function actAsRichEditorUser(User $user): void
{
    test()->actingAs($user);
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
}

it('moves rich editor image attachments to the attachments disk and rewrites the src', function (): void {
    $user = richEditorUser();
    $this->actingAs($user);
    $key = richEditorUpload(UploadedFile::fake()->image('photo.png', 10, 10));
    $temporaryPath = TemporaryUpload::directory().'/'.TemporaryUpload::nameFromKey($key);

    actAsRichEditorUser($user);
    $form = schema([RichEditor::make('body')->fileAttachmentsDirectory('posts')])->fill();
    $form->rawState(['body' => '<p>Hi</p><img src="'.$key.'" alt="photo.png">']);

    $body = $form->validate()['body'];

    expect($body)->not->toContain('tmp:')->not->toContain('blob:')
        ->and(preg_match('/src="([^"]+)"/', $body, $match))->toBe(1);

    $stored = collect(Storage::disk('public')->allFiles('posts'));

    expect($stored)->toHaveCount(1)
        ->and($match[1])->toBe(Storage::disk('public')->url($stored->first()))
        ->and(Storage::disk('local')->exists($temporaryPath))->toBeFalse();
});

it('drops images whose temporary key is invalid or belongs to someone else', function (): void {
    $this->actingAs(richEditorUser());
    $key = richEditorUpload(UploadedFile::fake()->image('photo.png', 10, 10));

    actAsRichEditorUser(richEditorUser('other@example.com'));
    $form = schema([RichEditor::make('body')])->fill();
    $form->rawState(['body' => '<p>Text</p><img src="'.$key.'"><img src="tmp:../../.env">']);

    expect($form->validate()['body'])->toBe('<p>Text</p>')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('stores markdown editor image attachments', function (): void {
    $user = richEditorUser();
    $this->actingAs($user);
    $key = richEditorUpload(UploadedFile::fake()->image('photo.png', 10, 10));

    actAsRichEditorUser($user);
    $form = schema([MarkdownEditor::make('body')])->fill();
    $form->rawState(['body' => "Intro\n\n![photo]({$key})"]);

    $body = $form->validate()['body'];
    $stored = Storage::disk('public')->allFiles();

    expect($stored)->toHaveCount(1)
        ->and($body)->toBe("Intro\n\n![photo](".Storage::disk('public')->url($stored[0]).')');
});
