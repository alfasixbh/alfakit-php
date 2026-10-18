<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use Closure;

/**
 * Lets a field change the value the validator sees without changing the form state, so
 * `trim()` and `stripCharacters()` apply to validation as well as to the saved data.
 */
trait CanMutateStateForValidation
{
    protected ?Closure $mutateStateForValidationUsing = null;

    /**
     * Transform the value before it is validated (`$state` is the current value). The form
     * state itself is left as the user typed it.
     */
    public function mutateStateForValidationUsing(?Closure $callback): static
    {
        $this->mutateStateForValidationUsing = $callback;

        return $this;
    }

    /**
     * Whether `getValidationStateByPath()` has anything to change. Override with the
     * field's own conditions (calling the parent).
     */
    protected function shouldMutateStateForValidation(): bool
    {
        return $this->mutateStateForValidationUsing !== null;
    }

    /**
     * Built-in, type-specific transformation (trimming, stripping characters). Override in
     * subclasses.
     */
    protected function mutateStateForValidation(mixed $state): mixed
    {
        return $state;
    }

    /**
     * The values the validator should see instead of the raw form state, keyed by absolute
     * state path. Empty when the field validates its state as is.
     *
     * @return array<string, mixed>
     */
    public function getValidationStateByPath(): array
    {
        if (! $this->shouldMutateStateForValidation()) {
            return [];
        }

        $state = $this->mutateStateForValidation($this->getState());

        if ($this->mutateStateForValidationUsing !== null) {
            $state = $this->evaluate($this->mutateStateForValidationUsing, ['state' => $state]);
        }

        return [$this->getStatePath() => $state];
    }
}
