<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/**
 * What the temporary upload endpoint accepts for one component: its maximum size and,
 * optionally, its accepted file types. Components that allow files larger than
 * `alfakit.uploads.max_size_kb` (`FileUpload::maxSize()`, rich editor attachments, the file
 * manager) send an encrypted policy with each upload, bound to the user (or guest session)
 * it was issued to. The owning component still validates the file when it is attached.
 */
final class UploadPolicy
{
    private const TTL_SECONDS = 43200;

    /**
     * @param  array<int, string>|null  $types  MIME types (`image/*`) and extensions (`.csv`)
     */
    private function __construct(public readonly int $maxKb, public readonly ?array $types = null) {}

    /**
     * The policy token of a component, or null when the defaults apply.
     *
     * @param  array<int, string>|null  $types
     */
    public static function token(?int $maxKb, ?array $types = null): ?string
    {
        $types = $types === null || $types === [] ? null : array_values(array_map('strval', $types));

        if (($maxKb === null || $maxKb <= 0) && $types === null) {
            return null;
        }

        return Crypt::encryptString((string) json_encode([
            'max' => $maxKb !== null && $maxKb > 0 ? $maxKb : null,
            'types' => $types,
            'owner' => TemporaryUpload::currentOwner(),
            'exp' => time() + self::TTL_SECONDS,
        ]));
    }

    /**
     * @param  array<int, string>|null  $types
     */
    public static function fromLimits(int $maxKb, ?array $types = null): self
    {
        return new self($maxKb, $types);
    }

    public static function defaults(): self
    {
        return new self(self::defaultMaxKb());
    }

    /**
     * The policy a request carries (`policy`), or the defaults when it has none or it isn't
     * valid for the current user (tampered, expired, issued to someone else).
     */
    public static function fromToken(mixed $token): self
    {
        if (! is_string($token) || $token === '') {
            return self::defaults();
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return self::defaults();
        }

        if (! is_array($data)
            || ! hash_equals((string) ($data['owner'] ?? ''), TemporaryUpload::currentOwner())
            || (int) ($data['exp'] ?? 0) < time()) {
            return self::defaults();
        }

        $types = isset($data['types']) && is_array($data['types']) ? array_values(array_map('strval', $data['types'])) : null;

        return new self(isset($data['max']) && is_numeric($data['max']) ? (int) $data['max'] : self::defaultMaxKb(), $types);
    }

    public static function defaultMaxKb(): int
    {
        return (int) config('alfakit.uploads.max_size_kb', 12288);
    }

    public function maxBytes(): int
    {
        return $this->maxKb * 1024;
    }

    /**
     * Whether a detected MIME type (and the client's file name, for `.ext` patterns) matches
     * the accepted types. Everything matches without a list.
     */
    public function accepts(string $mime, string $name): bool
    {
        if ($this->types === null) {
            return true;
        }

        $mime = strtolower($mime);
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        foreach ($this->types as $type) {
            $type = strtolower(trim($type));

            $matches = match (true) {
                str_starts_with($type, '.') => $type === '.'.$extension,
                str_ends_with($type, '/*') => str_starts_with($mime, substr($type, 0, -1)),
                default => $mime === $type,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
