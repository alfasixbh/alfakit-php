<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Attributes;

use Attribute;

/**
 * Lets the page's own frontend component call this public method through
 * `POST /_alfakit/pages/call` (`usePageMethod()` in React, the composable in Vue, the
 * rune helper in Svelte). Methods without the attribute can never be called.
 *
 * ```php
 * #[Expose(rateLimit: 30, authorize: ['create', Order::class], rules: ['items' => 'required|array'])]
 * public function checkout(array $items, ?string $note = null): array { … }
 * ```
 *
 * - `rateLimit`: at most this many calls per `decaySeconds` for each user (or IP address).
 * - `authorize`: a Gate ability (`'create'`), or the ability and its arguments
 *   (`['create', Order::class]`), checked for the panel user before anything runs.
 * - `rules`: Laravel validation rules for the named arguments, or the name of a page
 *   method returning them (it may type-hint the call's arguments as `array $arguments`).
 *   With rules, only validated arguments reach the method.
 * - `transaction`: run inside a database transaction; `null` follows the panel's
 *   `databaseTransactions()`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Expose
{
    /**
     * @param  string|array<int, mixed>|null  $authorize
     * @param  array<string, mixed>|string  $rules
     * @param  array<string, string>  $messages
     */
    public function __construct(
        public readonly ?int $rateLimit = null,
        public readonly int $decaySeconds = 60,
        public readonly string|array|null $authorize = null,
        public readonly array|string $rules = [],
        public readonly array $messages = [],
        public readonly ?bool $transaction = null,
    ) {}
}
