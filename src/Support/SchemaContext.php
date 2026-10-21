<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Identifies where a schema, table or action lives so internal endpoints can rebuild it.
 * The context is encrypted into an opaque token sent to the frontend; clients cannot
 * forge references to arbitrary classes. Tokens are bound to the user they were issued
 * to and expire after `alfakit.token_lifetime` minutes.
 */
final class SchemaContext
{
    /**
     * @param  class-string  $page
     * @param  array<string, mixed>  $parameters  Route parameters (e.g. record key, tenant)
     */
    public function __construct(
        public readonly string $panel,
        public readonly string $page,
        public readonly array $parameters = [],
        public readonly ?string $component = null,
        public readonly ?string $user = null,
        public readonly ?int $issuedAt = null,
    ) {}

    /** Identifier of the signed-in user on the current panel's guard, or null for guests. */
    public static function currentUser(): ?string
    {
        $id = alfakit()->getCurrentPanel()?->auth()->id();

        return $id === null ? null : (string) $id;
    }

    public function isExpired(): bool
    {
        $lifetime = (int) config('alfakit.token_lifetime', 1440);

        // now() rather than time(), so tests can travel past the lifetime ($this->travel(2)->days()).
        return $lifetime > 0 && $this->issuedAt !== null && $this->issuedAt + $lifetime * 60 < now()->getTimestamp();
    }

    public function toToken(): string
    {
        return Crypt::encryptString(json_encode([
            'panel' => $this->panel,
            'page' => $this->page,
            'parameters' => $this->parameters,
            'component' => $this->component,
            'user' => $this->user ?? self::currentUser(),
            'issued_at' => $this->issuedAt ?? now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    public static function fromToken(string $token): self
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw new BadRequestHttpException('Invalid Alfakit context token.');
        }

        return new self(
            panel: (string) $data['panel'],
            page: (string) $data['page'],
            parameters: (array) ($data['parameters'] ?? []),
            component: $data['component'] ?? null,
            user: isset($data['user']) ? (string) $data['user'] : null,
            issuedAt: isset($data['issued_at']) ? (int) $data['issued_at'] : null,
        );
    }

    public function withComponent(?string $component): self
    {
        return new self($this->panel, $this->page, $this->parameters, $component, $this->user, $this->issuedAt);
    }
}
