<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\Rules;

use AlfaSix\Alfakit\Auth\Throttle;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks a value with `$passes`, counting every check against a rate limiter. Once the limit
 * is reached the value is not checked at all, so the error doesn't reveal whether it was right.
 */
class ThrottledRule implements ValidationRule
{
    /**
     * @param  Closure(mixed): bool  $passes
     * @param  Closure(): string  $message
     */
    public function __construct(
        protected string $key,
        protected Closure $passes,
        protected Closure $message,
        protected int $maxAttempts = Throttle::MAX_ATTEMPTS,
        protected int $decaySeconds = Throttle::DECAY_SECONDS,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (RateLimiter::tooManyAttempts($this->key, $this->maxAttempts)) {
            $fail(Throttle::message($this->key));

            return;
        }

        RateLimiter::hit($this->key, $this->decaySeconds);

        if (! ($this->passes)($value)) {
            $fail(($this->message)());
        }
    }
}
