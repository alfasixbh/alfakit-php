<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Rules;

use AlfaSix\Alfakit\Auth\MultiFactor\App\AppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\MultiFactorChallenge;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Verifies the code of a {@see MultiFactorChallenge} against the method the user picked,
 * sharing the login challenge's rate limiter. Fails closed: no user, no second factor or
 * an unknown method never pass.
 */
class VerifiesMultiFactorChallenge implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    protected array $data = [];

    public function __construct(protected MultiFactorChallenge $challenge) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $panel = $this->challenge->getPanel();
        $user = $this->challenge->getUser();
        $methods = $user === null ? [] : $this->challenge->getMethods($user);

        if ($user === null || $methods === []) {
            $fail(__('alfakit::multi-factor.challenge.unavailable'));

            return;
        }

        $throttleKey = MultiFactorAuthentication::throttleKey($panel, $user);

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $fail(__('alfakit::multi-factor.challenge.throttled', ['seconds' => RateLimiter::availableIn($throttleKey)]));

            return;
        }

        $method = Arr::get($this->data, $this->challenge->getMethodStatePath());
        $method = is_string($method) && isset($methods[$method]) ? $method : array_key_first($methods);
        $provider = $methods[$method];
        $code = trim(is_scalar($value) ? (string) $value : '');

        $isValid = $code !== '' && ($method === MultiFactorChallenge::RECOVERY
            ? $provider instanceof AppAuthentication && $provider->verifyRecoveryCode($user, $code)
            : $provider->verifyChallengeCode($user, $code));

        if (! $isValid) {
            RateLimiter::hit($throttleKey, 300);

            $fail(__('alfakit::multi-factor.invalid_code'));
        }
    }
}
