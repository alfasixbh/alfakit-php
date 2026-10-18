<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Support\ChunkedUpload;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use AlfaSix\Alfakit\Support\UploadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Temporary uploads. Files land on the temporary disk and are moved by FileUpload when
 * the owning form is submitted, after the field validates their type and size.
 *
 * Files larger than `alfakit.uploads.chunk_size` are sent in chunks (resumable): `start`
 * returns an upload id and the chunks already received, each chunk is posted with its
 * index, and `complete` assembles them locally, validates the whole file like a single
 * upload and stores it on the temporary disk (any disk, S3 included). Chunks live under
 * the uploader's own directory, so nobody else can add to, read or finish an upload.
 */
class UploadController
{
    public function store(Request $request): JsonResponse
    {
        $policy = UploadPolicy::fromToken($request->input('policy'));

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.$policy->maxKb],
        ]);

        /** @var UploadedFile $file */
        $file = $validated['file'];

        return response()->json($this->storeTemporaryFile($file, $file->getClientOriginalName(), $policy));
    }

    public function destroy(Request $request): JsonResponse
    {
        $key = (string) $request->input('key', '');

        abort_if(TemporaryUpload::nameFromKey($key) === null, 400);

        TemporaryUpload::find($key)?->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * POST {name, size, policy?, upload?}: start a chunked upload, or resume `upload` when it
     * is the same file. Returns the id, the chunk size and the indexes already received.
     */
    public function startChunked(Request $request): JsonResponse
    {
        $policy = UploadPolicy::fromToken($request->input('policy'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.$policy->maxBytes()],
            'upload' => ['nullable', 'string'],
        ], [
            'size.max' => __('alfakit::forms.file_upload.too_large'),
        ]);

        $name = (string) $validated['name'];
        $size = (int) $validated['size'];

        $resumed = is_string($validated['upload'] ?? null) ? ChunkedUpload::find($validated['upload']) : null;

        if ($resumed !== null && $resumed->name === $name && $resumed->size === $size) {
            return response()->json($resumed->toResponse());
        }

        // A client-supplied type is only a hint for the extension check; the stored file's
        // type is detected again on completion.
        $hint = (string) $request->input('type', '');

        if ($hint !== '' && ! $policy->accepts($hint, $name)) {
            throw ValidationException::withMessages(['file' => __('alfakit::forms.file_upload.invalid_type')]);
        }

        return response()->json(ChunkedUpload::start($name, $size, $policy)->toResponse());
    }

    /**
     * GET: the chunks received so far (to resume after a reload or a network failure).
     */
    public function showChunked(string $upload): JsonResponse
    {
        return response()->json($this->findChunked($upload)->toResponse());
    }

    /**
     * POST {index, chunk}: store one chunk. Sending the same index again replaces it, so a
     * failed chunk can simply be retried.
     */
    public function storeChunk(Request $request, string $upload): JsonResponse
    {
        $chunked = $this->findChunked($upload);

        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:'.($chunked->chunkCount() - 1)],
            'chunk' => ['required', 'file'],
        ]);

        /** @var UploadedFile $chunk */
        $chunk = $validated['chunk'];
        $index = (int) $validated['index'];

        if ($chunk->getSize() !== $chunked->expectedChunkSize($index)) {
            throw ValidationException::withMessages(['chunk' => __('alfakit::forms.file_upload.chunk_failed')]);
        }

        $chunked->storeChunk($index, $chunk);

        return response()->json(['received' => $chunked->receivedChunks()]);
    }

    /**
     * POST: assemble the chunks, validate the file and store it like a single upload.
     */
    public function completeChunked(string $upload): JsonResponse
    {
        $chunked = $this->findChunked($upload);

        if (count($chunked->receivedChunks()) !== $chunked->chunkCount()) {
            throw ValidationException::withMessages(['file' => __('alfakit::forms.file_upload.chunk_failed')]);
        }

        $path = $chunked->assemble();

        try {
            if ($path === null || filesize($path) !== $chunked->size) {
                throw ValidationException::withMessages(['file' => __('alfakit::forms.file_upload.chunk_failed')]);
            }

            $response = $this->storeTemporaryFile(new UploadedFile($path, $chunked->name, null, null, true), $chunked->name, $chunked->policy());
        } finally {
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }

            $chunked->delete();
        }

        return response()->json($response);
    }

    /**
     * DELETE: cancel a chunked upload and delete its chunks.
     */
    public function destroyChunked(string $upload): JsonResponse
    {
        ChunkedUpload::find($upload)?->delete();

        return response()->json(['ok' => true]);
    }

    protected function findChunked(string $upload): ChunkedUpload
    {
        $chunked = ChunkedUpload::find($upload);

        abort_if($chunked === null, 404);

        return $chunked;
    }

    /**
     * Validate the detected type and store a file on the temporary disk with its metadata.
     *
     * @return array{key: string, name: string, size: int|false, mime: string}
     */
    protected function storeTemporaryFile(UploadedFile $file, string $originalName, UploadPolicy $policy): array
    {
        // Trust the detected type, never the client's name or Content-Type.
        $mime = (string) $file->getMimeType();
        $blocked = array_map('strtolower', (array) config('alfakit.uploads.blocked_mime_types', []));

        if (in_array(strtolower($mime), $blocked, true) || ! $policy->accepts($mime, $originalName)) {
            throw ValidationException::withMessages(['file' => __('alfakit::forms.file_upload.invalid_type')]);
        }

        $extension = strtolower((string) $file->guessExtension());
        $name = (string) Str::ulid().(preg_match('/^[a-z0-9]{1,10}$/', $extension) === 1 ? ".{$extension}" : '');
        $directory = TemporaryUpload::directory();
        $disk = TemporaryUpload::disk();

        $path = $file->storeAs($directory, $name, ['disk' => config('alfakit.uploads.temporary_disk', config('filesystems.default'))]);

        abort_if($path === false, 500, 'The file could not be stored.');

        $disk->put("{$directory}/{$name}.json", json_encode([
            'name' => $originalName,
            'size' => $file->getSize(),
            'mime' => $mime,
            'owner' => TemporaryUpload::currentOwner(),
            'uploaded_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return [
            'key' => TemporaryUpload::PREFIX.$name,
            'name' => $originalName,
            'size' => $file->getSize(),
            'mime' => $mime,
        ];
    }
}
