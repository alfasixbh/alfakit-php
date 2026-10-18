<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use Closure;

class Checkbox extends Field
{
    use Concerns\CanFixIndistinctState;

    protected bool|Closure $isInline = true;

    protected bool|Closure $isAccepted = false;

    protected bool|Closure $isDeclined = false;

    public function inline(bool|Closure $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    /**
     * Must be checked (e.g. terms of service).
     */
    public function accepted(bool|Closure $condition = true): static
    {
        $this->isAccepted = $condition;

        return $this;
    }

    public function declined(bool|Closure $condition = true): static
    {
        $this->isDeclined = $condition;

        return $this;
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return false;
    }

    protected function getTypeValidationRules(): array
    {
        $rules = ['boolean'];

        if ($this->evaluate($this->isAccepted)) {
            $rules[] = 'accepted';
        }

        if ($this->evaluate($this->isDeclined)) {
            $rules[] = 'declined';
        }

        return $rules;
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        return (bool) $state;
    }

    protected function getSchemaProps(): array
    {
        return [
            'inline' => (bool) $this->evaluate($this->isInline),
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
