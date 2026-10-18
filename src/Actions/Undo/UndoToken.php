<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Undo;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The short-lived, single-use token behind an "Undo" button.
 *
 * What the undo needs (records, snapshot, the action's context) stays on the server, in
 * the cache under a random nonce; the browser only gets an encrypted token naming that
 * nonce, the user, panel and tenant it was issued for and when it expires. Redeeming pulls
 * the cache entry, so a token works once.
 */
final class UndoToken
{
    public const CACHE_PREFIX = 'alfakit-undo:';

    /** Seconds the server still accepts a token after its countdown ended (latency). */
    public const GRACE_SECONDS = 5;

    /**
     * @param  array{nonce: string, panel: string, user: string|null, tenant: string|null, expires: int}  $claims
     */
    private function __construct(public readonly array $claims) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function issue(array $payload, int $seconds, string $panel, ?string $user, ?string $tenant): string
    {
        $nonce = Str::random(40);
        $expires = now()->getTimestamp() + $seconds;

        self::cache()->put(self::CACHE_PREFIX.$nonce, $payload, $seconds + self::GRACE_SECONDS + 5);

        return Crypt::encryptString(json_encode([
            'n' => $nonce,
            'p' => $panel,
            'u' => $user,
            't' => $tenant,
            'e' => $expires,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Read a token sent by the browser; a forged or altered token is refused.
     */
    public static function decode(string $token): self
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new BadRequestHttpException('Invalid undo token.');
        }

        if (! is_array($data) || ! is_string($data['n'] ?? null) || ! is_string($data['p'] ?? null) || ! is_int($data['e'] ?? null)) {
            throw new BadRequestHttpException('Invalid undo token.');
        }

        return new self([
            'nonce' => $data['n'],
            'panel' => $data['p'],
            'user' => isset($data['u']) ? (string) $data['u'] : null,
            'tenant' => isset($data['t']) ? (string) $data['t'] : null,
            'expires' => $data['e'],
        ]);
    }

    public function isExpired(): bool
    {
        return now()->getTimestamp() > $this->claims['expires'] + self::GRACE_SECONDS;
    }

    /**
     * Take the token's payload out of the cache: null when it was used (or has expired).
     *
     * @return array<string, mixed>|null
     */
    public function redeem(): ?array
    {
        $payload = self::cache()->pull(self::CACHE_PREFIX.$this->claims['nonce']);

        return is_array($payload) ? $payload : null;
    }

    private static function cache(): Repository
    {
        $store = config('alfakit.undo.cache_store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
