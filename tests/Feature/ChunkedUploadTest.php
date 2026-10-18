<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Support\ChunkedUpload;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Support\UploadPolicy;
use AlfaSix\Alfakit\Tests\Fixtures\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * Resumable chunked uploads: start → chunk N → complete, scoped to the uploader, validated
 * on completion and stored on the temporary disk like a single upload.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config(['alfakit.uploads.temporary_disk' => 'local', 'alfakit.uploads.chunk_size' => 256 * 1024]);
});

function chunkUser(string $email = 'chunks@example.com'): User
{
    return User::query()->create(['name' => 'Chunks', 'email' => $email, 'password' => 'password']);
}

/** Act as a panel user, so policies issued in the test are bound to them. */
function actAsChunkUser(string $email = 'chunks@example.com'): void
{
    test()->actingAs(chunkUser($email));
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
}

/**
 * A PNG of about `$bytes` bytes (a valid header followed by padding).
 */
function chunkPng(int $bytes): string
{
    $file = UploadedFile::fake()->image('a.png', 4, 4);
    $png = (string) file_get_contents($file->getRealPath());

    return $png.str_repeat("\0", max(0, $bytes - strlen($png)));
}

/**
 * A policy issued as a page render would: for the panel user.
 *
 * @param  array<int, string>|null  $types
 */
function chunkPolicy(?int $maxKb, ?array $types = null): ?string
{
    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));

    return UploadPolicy::token($maxKb, $types);
}

function startChunks(array $data): Illuminate\Testing\TestResponse
{
    return test()->postJson('/admin/_alfakit/uploads/chunks', $data);
}

function sendChunk(string $upload, int $index, string $bytes): Illuminate\Testing\TestResponse
{
    return test()->post("/admin/_alfakit/uploads/chunks/{$upload}", [
        'index' => $index,
        'chunk' => UploadedFile::fake()->createWithContent("{$index}.part", $bytes),
    ], ['Accept' => 'application/json']);
}

it('uploads a file in chunks, resumes it and stores it like a single upload', function (): void {
    $this->actingAs(chunkUser());
    $contents = chunkPng(600 * 1024);
    $size = strlen($contents);

    $start = startChunks(['name' => 'photo.png', 'size' => $size, 'type' => 'image/png'])->assertOk()
        ->assertJson(['chunkSize' => 256 * 1024, 'chunks' => 3, 'received' => []]);
    $upload = $start->json('upload');

    $chunks = str_split($contents, 256 * 1024);
    sendChunk($upload, 0, $chunks[0])->assertOk()->assertJson(['received' => [0]]);
    // A retried chunk replaces the first attempt.
    sendChunk($upload, 0, $chunks[0])->assertOk()->assertJson(['received' => [0]]);
    sendChunk($upload, 2, $chunks[2])->assertOk()->assertJson(['received' => [0, 2]]);

    // Resuming the same file returns the chunks already received; a missing one blocks completion.
    startChunks(['name' => 'photo.png', 'size' => $size, 'upload' => $upload])->assertOk()
        ->assertJson(['upload' => $upload, 'received' => [0, 2]]);
    $this->getJson("/admin/_alfakit/uploads/chunks/{$upload}")->assertOk()->assertJson(['received' => [0, 2]]);
    $this->postJson("/admin/_alfakit/uploads/chunks/{$upload}/complete")->assertStatus(422);

    sendChunk($upload, 1, $chunks[1])->assertOk();

    $result = $this->postJson("/admin/_alfakit/uploads/chunks/{$upload}/complete")->assertOk()
        ->assertJson(['name' => 'photo.png', 'size' => $size, 'mime' => 'image/png'])
        ->json();

    alfakit()->setCurrentPanel(alfakit()->getPanel('admin'));
    $temporary = TemporaryUpload::find($result['key']);

    expect($result['key'])->toEndWith('.png')
        ->and($temporary?->size())->toBe($size)
        ->and(Storage::disk('local')->get((string) $temporary?->path()))->toBe($contents)
        // The chunks are gone.
        ->and(Storage::disk('local')->allFiles(ChunkedUpload::root()))->toBe([]);
});

it('checks chunk indexes and sizes', function (): void {
    $this->actingAs(chunkUser());
    $upload = startChunks(['name' => 'a.png', 'size' => 300 * 1024])->json('upload');

    sendChunk($upload, 2, 'x')->assertStatus(422)->assertJsonValidationErrors('index');
    sendChunk($upload, 0, 'too short')->assertStatus(422)->assertJsonValidationErrors('chunk');
    // The last chunk is the remainder.
    sendChunk($upload, 1, str_repeat('x', 44 * 1024))->assertOk();
});

it('scopes chunked uploads to the user who started them', function (): void {
    $this->actingAs(chunkUser());
    $upload = startChunks(['name' => 'a.png', 'size' => 300 * 1024])->json('upload');

    $this->actingAs(chunkUser('other@example.com'));

    sendChunk($upload, 1, str_repeat('x', 44 * 1024))->assertNotFound();
    $this->getJson("/admin/_alfakit/uploads/chunks/{$upload}")->assertNotFound();
    $this->postJson("/admin/_alfakit/uploads/chunks/{$upload}/complete")->assertNotFound();
    startChunks(['name' => 'a.png', 'size' => 300 * 1024, 'upload' => $upload])->assertOk()
        ->assertJsonMissing(['upload' => $upload]);
    $this->getJson('/admin/_alfakit/uploads/chunks/../../secret')->assertNotFound();
});

it('limits the size to the configured maximum or the component\'s upload policy', function (): void {
    actAsChunkUser();
    config(['alfakit.uploads.max_size_kb' => 512]);

    startChunks(['name' => 'big.png', 'size' => 600 * 1024])->assertStatus(422)->assertJsonValidationErrors('size');

    // `FileUpload::maxSize()` above the default travels in the field's encrypted policy.
    $policy = chunkPolicy(2048);

    startChunks(['name' => 'big.png', 'size' => 600 * 1024, 'policy' => $policy])->assertOk();
    startChunks(['name' => 'big.png', 'size' => 3000 * 1024, 'policy' => $policy])->assertStatus(422);
    // A tampered policy falls back to the defaults.
    startChunks(['name' => 'big.png', 'size' => 600 * 1024, 'policy' => $policy.'x'])->assertStatus(422);

    // Single uploads honour the policy too.
    $single = fn (?string $policy) => $this->post('/admin/_alfakit/uploads', array_filter([
        'file' => UploadedFile::fake()->create('big.pdf', 600, 'application/pdf'),
        'policy' => $policy,
    ]), ['Accept' => 'application/json']);

    $single(null)->assertStatus(422);
    $single($policy)->assertOk();

    // A policy is bound to the user it was issued to.
    actAsChunkUser('other@example.com');
    startChunks(['name' => 'big.png', 'size' => 600 * 1024, 'policy' => $policy])->assertStatus(422);
});

it('validates the assembled file\'s detected type on completion', function (): void {
    actAsChunkUser();

    $html = '<html><script>alert(1)</script></html>'.str_repeat(' ', 300 * 1024);
    $upload = startChunks(['name' => 'photo.png', 'size' => strlen($html), 'type' => 'image/png'])->json('upload');

    foreach (str_split($html, 256 * 1024) as $index => $chunk) {
        sendChunk($upload, $index, $chunk)->assertOk();
    }

    $this->postJson("/admin/_alfakit/uploads/chunks/{$upload}/complete")->assertStatus(422)->assertJsonValidationErrors('file');

    // Accepted types from the policy: refused up front from the browser's type, and again
    // from the detected type.
    $policy = chunkPolicy(null, ['application/pdf']);

    startChunks(['name' => 'a.png', 'size' => 300 * 1024, 'type' => 'image/png', 'policy' => $policy])
        ->assertStatus(422)->assertJsonValidationErrors('file');

    $png = chunkPng(300 * 1024);
    $upload = startChunks(['name' => 'a.pdf', 'size' => strlen($png), 'policy' => $policy])->json('upload');

    foreach (str_split($png, 256 * 1024) as $index => $chunk) {
        sendChunk($upload, $index, $chunk)->assertOk();
    }

    $this->postJson("/admin/_alfakit/uploads/chunks/{$upload}/complete")->assertStatus(422)->assertJsonValidationErrors('file');
});

it('cancels a chunked upload and prunes abandoned chunks', function (): void {
    $this->actingAs(chunkUser());
    $first = startChunks(['name' => 'a.png', 'size' => 300 * 1024])->json('upload');
    sendChunk($first, 0, str_repeat('x', 256 * 1024))->assertOk();

    $this->deleteJson("/admin/_alfakit/uploads/chunks/{$first}")->assertOk();
    expect(Storage::disk('local')->allFiles(ChunkedUpload::root()))->toBe([]);

    $second = startChunks(['name' => 'a.png', 'size' => 300 * 1024])->json('upload');
    sendChunk($second, 0, str_repeat('x', 256 * 1024))->assertOk();

    $this->artisan('alfakit:prune-uploads')->assertSuccessful();
    expect(Storage::disk('local')->allFiles(ChunkedUpload::root()))->not->toBe([]);

    $this->travel(2)->days();
    $this->artisan('alfakit:prune-uploads')->expectsOutputToContain('1 unfinished chunked upload')->assertSuccessful();
    expect(Storage::disk('local')->allFiles(ChunkedUpload::root()))->toBe([])
        ->and(Storage::disk('local')->directories(ChunkedUpload::root()))->toBe([]);
});

it('sends the chunk size and each component\'s upload policy to the renderer', function (): void {
    actAsChunkUser();

    $panel = alfakit()->getPanel('admin');

    expect($panel->getEndpoints()['uploadChunks'])->toEndWith('/admin/_alfakit/uploads/chunks');

    [$video, $plain] = schema([
        FileUpload::make('video')->maxSize(512000)->acceptedFileTypes(['video/mp4']),
        FileUpload::make('plain'),
    ])->fill()->toSchema();
    $policy = UploadPolicy::fromToken($video['uploadPolicy']);

    expect($policy->maxKb)->toBe(512000)
        ->and($policy->types)->toBe(['video/mp4'])
        ->and($plain['uploadPolicy'] ?? null)->toBeNull();
});
