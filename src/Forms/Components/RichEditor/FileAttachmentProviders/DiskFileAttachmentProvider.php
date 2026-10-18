<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders;

use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use AlfaSix\Alfakit\Forms\Components\RichEditor\FileAttachmentUrls;
use AlfaSix\Alfakit\Forms\Components\RichEditor\RichContentAttribute;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The default file attachment provider: files are stored as `{directory}/{ulid}.{ext}` on a
 * disk and referenced by the disk's URL. Private files are shown through temporary URLs
 * (`FileAttachmentUrls`).
 */
class DiskFileAttachmentProvider implements FileAttachmentProvider
{
    protected ?RichContentAttribute $attribute = null;

    public function __construct(
        protected string $disk,
        protected ?string $directory = null,
        protected ?string $visibility = null,
        protected ?int $temporaryUrlExpiry = null,
    ) {}

    public static function make(string $disk, ?string $directory = null, ?string $visibility = null, ?int $temporaryUrlExpiry = null): static
    {
        return new static($disk, $directory, $visibility, $temporaryUrlExpiry);
    }

    public function attribute(RichContentAttribute $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function saveUploadedFileAttachment(TemporaryUpload $file): ?string
    {
        $stream = TemporaryUpload::disk()->readStream($file->path());

        if ($stream === null) {
            return null;
        }

        $extension = $file->extension();
        $directory = trim((string) $this->directory, '/');
        $path = ($directory !== '' ? $directory.'/' : '').Str::ulid().($extension !== '' ? ".{$extension}" : '');
        $disk = Storage::disk($this->disk);

        try {
            $disk->writeStream($path, $stream, ['visibility' => $this->getVisibility()]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return $disk->url($path);
        } catch (Throwable) {
            $disk->delete($path);

            return null;
        }
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        if (! is_string($file) || $this->getVisibility() !== 'private') {
            return null;
        }

        return (new FileAttachmentUrls($this->disk, $this->directory, $this->temporaryUrlExpiry))->signUrl($file);
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return null;
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    /**
     * Files stay on the disk: other content (revisions, copies) may still reference them.
     *
     * @param  array<string>  $exceptIds
     */
    public function cleanUpFileAttachments(array $exceptIds): void {}

    protected function getVisibility(): string
    {
        return $this->visibility ?? 'public';
    }
}
