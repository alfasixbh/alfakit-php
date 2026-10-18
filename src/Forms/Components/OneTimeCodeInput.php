<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use Closure;

/**
 * A one-time code (multi-factor authentication, email confirmation): a single numeric
 * input with `autocomplete="one-time-code"`, so browsers and password managers can fill
 * the code from an SMS or email. The state is the code as a string of digits.
 *
 *     OneTimeCodeInput::make('code')->length(6)->submitOnCompletion()
 */
class OneTimeCodeInput extends Field
{
    protected int|Closure $length = 6;

    protected bool|Closure $shouldSubmitOnCompletion = false;

    /**
     * How many digits the code has (6 by default). Codes of another length fail validation.
     */
    public function length(int|Closure $length): static
    {
        $this->length = $length;

        return $this;
    }

    public function getLength(): int
    {
        return max(1, (int) $this->evaluate($this->length));
    }

    /**
     * Submit the form as soon as every digit is entered (typed, pasted or autofilled).
     * Each complete code is submitted once.
     */
    public function submitOnCompletion(bool|Closure $condition = true): static
    {
        $this->shouldSubmitOnCompletion = $condition;

        return $this;
    }

    public function shouldSubmitOnCompletion(): bool
    {
        return (bool) $this->evaluate($this->shouldSubmitOnCompletion);
    }

    protected function getTypeValidationRules(): array
    {
        return ['digits:'.$this->getLength()];
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if (! is_scalar($state)) {
            return null;
        }

        $code = (string) preg_replace('/\s+/', '', (string) $state);

        return $code === '' ? null : $code;
    }

    protected function getSchemaProps(): array
    {
        return [
            'length' => $this->getLength(),
            'submitOnCompletion' => $this->shouldSubmitOnCompletion() ?: null,
        ];
    }
}
