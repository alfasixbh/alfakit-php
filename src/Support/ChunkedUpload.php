<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

/**
 * A resumable upload in progress: its manifest and chunks live on the temporary disk under
 * `{temporary_directory}/chunks/{owner hash}/{id}/`, so an id only resolves for the user
 * (or guest session) that started it. `alfakit:prune-uploads` deletes abandoned chunks.
 */
final class ChunkedUpload
{
    private const ID_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * @param  array<int, string>|null  $types
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $size,
        public readonly int $chunkSize,
        private readonly int $maxKb,
        private readonly ?array $types,
    ) {}

    /**
     * The chunk size in bytes (`alfakit.uploads.chunk_size`, 5 MB by default). Files up to
     * this size are sent in one request.
     */
    public static function configuredChunkSize(): int
    {
        return max(256 * 1024, (int) config('alfakit.uploads.chunk_size', 5 * 1024 * 1024));
    }

    public static function root(): string
    {
        return TemporaryUpload::directory().'/chunks';
    }

    public static function start(string $name, int $size, UploadPolicy $policy): self
    {
        $upload = new self((string) Str::ulid(), $name, $size, self::configuredChunkSize(), $policy->maxKb, $policy->types);

        TemporaryUpload::disk()->put($upload->directory().'/manifest.json', json_encode([
            'name' => $name,
            'size' => $size,
            'chunk_size' => $upload->chunkSize,
            'max' => $policy->maxKb,
            'types' => $policy->types,
            'started_at' => now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return $upload;
    }

    /**
     * The current user's upload with this id, or null.
     */
    public static function find(string $id): ?self
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        $manifest = self::ownerDirectory()."/{$id}/manifest.json";
        $disk = TemporaryUpload::disk();

        try {
            $data = $disk->exists($manifest) ? json_decode((string) $disk->get($manifest), true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ! isset($data['name'], $data['size'], $data['chunk_size'])) {
            return null;
        }

        return new self(
            $id,
            (string) $data['name'],
            (int) $data['size'],
            max(1, (int) $data['chunk_size']),
            (int) ($data['max'] ?? UploadPolicy::defaultMaxKb()),
            isset($data['types']) && is_array($data['types']) ? array_values(array_map('strval', $data['types'])) : null,
        );
    }

    private static function ownerDirectory(): string
    {
        return self::root().'/'.hash('sha256', TemporaryUpload::currentOwner());
    }

    public function directory(): string
    {
        return self::ownerDirectory().'/'.$this->id;
    }

    public function chunkCount(): int
    {
        return (int) max(1, ceil($this->size / $this->chunkSize));
    }

    /**
     * Every chunk is `chunkSize` bytes except the last one.
     */
    public function expectedChunkSize(int $index): int
    {
        return $index < $this->chunkCount() - 1 ? $this->chunkSize : $this->size - $this->chunkSize * ($this->chunkCount() - 1);
    }

    public function storeChunk(int $index, UploadedFile $chunk): void
    {
        $stream = fopen((string) $chunk->getRealPath(), 'rb');

        abort_if($stream === false, 500, 'The chunk could not be stored.');

        try {
            TemporaryUpload::disk()->writeStream($this->directory()."/{$index}.part", $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    public function receivedChunks(): array
    {
        $received = [];

        foreach (TemporaryUpload::disk()->files($this->directory()) as $file) {
            if (preg_match('/\/(\d+)\.part$/', $file, $match) === 1 && (int) $match[1] < $this->chunkCount()) {
                $received[] = (int) $match[1];
            }
        }

        sort($received);

        return $received;
    }

    /**
     * Concatenate the chunks into a local temporary file (the disk may be remote) and return
     * its path, or null when a chunk is missing or the total exceeds the declared size.
     */
    public function assemble(): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'alfakit-upload-');

        if ($path === false) {
            return null;
        }

        $target = fopen($path, 'wb');

        if ($target === false) {
            return null;
        }

        $disk = TemporaryUpload::disk();
        $written = 0;

        try {
            for ($index = 0; $index < $this->chunkCount(); $index++) {
                $source = $disk->readStream($this->directory()."/{$index}.part");

                if (! is_resource($source)) {
                    return $this->discardAssembly($path);
                }

                $written += (int) stream_copy_to_stream($source, $target);
                fclose($source);

                if ($written > $this->size) {
                    return $this->discardAssembly($path);
                }
            }
        } finally {
            fclose($target);
        }

        return $path;
    }

    private function discardAssembly(string $path): null
    {
        @unlink($path);

        return null;
    }

    public function policy(): UploadPolicy
    {
        return UploadPolicy::fromLimits($this->maxKb, $this->types);
    }

    public function delete(): void
    {
        TemporaryUpload::disk()->deleteDirectory($this->directory());
    }

    /**
     * @return array{upload: string, chunkSize: int, chunks: int, received: array<int, int>}
     */
    public function toResponse(): array
    {
        return [
            'upload' => $this->id,
            'chunkSize' => $this->chunkSize,
            'chunks' => $this->chunkCount(),
            'received' => $this->receivedChunks(),
        ];
    }
}
