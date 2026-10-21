<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A file on the temporary disk, addressed by the `tmp:` key the upload endpoint returned.
 * Keys are a ULID plus a server-derived extension; anything else is rejected, so a key can
 * never point outside the temporary directory. Each upload records who made it, and only
 * that user (or guest session) can attach or delete it.
 */
final class TemporaryUpload
{
    public const PREFIX = 'tmp:';

    private const NAME_PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}(\.[a-z0-9]{1,10})?$/i';

    /**
     * @param  array<string, mixed>  $meta
     */
    private function __construct(public readonly string $name, public readonly array $meta) {}

    public static function disk(): Filesystem
    {
        return Storage::disk(config('alfakit.uploads.temporary_disk', config('filesystems.default')));
    }

    public static function directory(): string
    {
        return trim((string) config('alfakit.uploads.temporary_directory', 'alfakit-tmp'), '/');
    }

    public static function isKey(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }

    /**
     * The stored file name for a key, or null when the key is malformed.
     */
    public static function nameFromKey(string $key): ?string
    {
        if (! self::isKey($key)) {
            return null;
        }

        $name = substr($key, strlen(self::PREFIX));

        return preg_match(self::NAME_PATTERN, $name) === 1 ? $name : null;
    }

    /**
     * Find an upload that exists and belongs to the current user or session.
     */
    public static function find(string $key): ?self
    {
        $name = self::nameFromKey($key);

        if ($name === null) {
            return null;
        }

        $disk = self::disk();
        $path = self::directory().'/'.$name;

        if (! $disk->exists($path) || ! $disk->exists($path.'.json')) {
            return null;
        }

        try {
            $meta = json_decode((string) $disk->get($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($meta) || ! hash_equals((string) ($meta['owner'] ?? ''), self::currentOwner())) {
            return null;
        }

        return new self($name, $meta);
    }

    /**
     * Who is uploading: the panel user, or the session for guest pages.
     */
    public static function currentOwner(): string
    {
        $user = SchemaContext::currentUser();

        if ($user !== null) {
            return 'user:'.$user;
        }

        return 'session:'.(app()->bound('session') ? session()->getId() : '');
    }

    public function path(): string
    {
        return self::directory().'/'.$this->name;
    }

    public function originalName(): string
    {
        return (string) ($this->meta['name'] ?? $this->name);
    }

    /**
     * The name the file had on the user's device, as Laravel's `UploadedFile` (and
     * Filament's `TemporaryUploadedFile`) name it, so `fn ($file) => $file->getClientOriginalName()`
     * closures work unchanged.
     */
    public function getClientOriginalName(): string
    {
        return $this->originalName();
    }

    /** The original name's extension, as `UploadedFile::getClientOriginalExtension()`. */
    public function getClientOriginalExtension(): string
    {
        return (string) pathinfo($this->originalName(), PATHINFO_EXTENSION);
    }

    public function extension(): string
    {
        return (string) pathinfo($this->name, PATHINFO_EXTENSION);
    }

    public function mimeType(): string
    {
        return (string) ($this->meta['mime'] ?? 'application/octet-stream');
    }

    public function size(): int
    {
        return (int) ($this->meta['size'] ?? 0);
    }

    public function delete(): void
    {
        self::disk()->delete([$this->path(), $this->path().'.json']);
    }
}
