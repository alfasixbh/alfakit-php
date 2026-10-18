<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use Closure;

class Textarea extends Field
{
    use Concerns\CanBeTranslatable;
    use Concerns\CanTrimState;

    protected int|Closure|null $rows = null;

    protected int|Closure|null $cols = null;

    protected int|Closure|null $minLength = null;

    protected int|Closure|null $maxLength = null;

    protected bool|Closure $isAutosized = false;

    protected bool|Closure $isGrammarlyDisabled = false;

    public function rows(int|Closure|null $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    public function cols(int|Closure|null $cols): static
    {
        $this->cols = $cols;

        return $this;
    }

    public function minLength(int|Closure|null $length): static
    {
        $this->minLength = $length;

        return $this;
    }

    public function maxLength(int|Closure|null $length): static
    {
        $this->maxLength = $length;

        return $this;
    }

    /**
     * Exactly this many characters (`minLength()` and `maxLength()` together).
     */
    public function length(int|Closure $length): static
    {
        return $this->minLength($length)->maxLength($length);
    }

    public function autosize(bool|Closure $condition = true): static
    {
        $this->isAutosized = $condition;

        return $this;
    }

    /**
     * Keep the Grammarly browser extension out of this textarea (`data-gramm="false"`…).
     */
    public function disableGrammarly(bool|Closure $condition = true): static
    {
        $this->isGrammarlyDisabled = $condition;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraInputAttributes(): array
    {
        $attributes = parent::getExtraInputAttributes();

        if (! $this->evaluate($this->isGrammarlyDisabled)) {
            return $attributes;
        }

        return [
            'data-gramm' => 'false',
            'data-gramm_editor' => 'false',
            'data-enable-grammarly' => 'false',
            ...$attributes,
        ];
    }

    protected function getTypeValidationRules(): array
    {
        $rules = ['string'];

        if (($min = $this->evaluate($this->minLength)) !== null) {
            $rules[] = "min:{$min}";
        }

        if (($max = $this->evaluate($this->maxLength)) !== null) {
            $rules[] = "max:{$max}";
        }

        return $rules;
    }

    protected function shouldMutateStateForValidation(): bool
    {
        return parent::shouldMutateStateForValidation() || $this->isTrimmed();
    }

    protected function mutateStateForValidation(mixed $state): mixed
    {
        return $this->trimState($state);
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        $state = $this->trimState($state);

        return $state === '' ? null : $state;
    }

    protected function getSchemaProps(): array
    {
        return [
            'rows' => $this->evaluate($this->rows),
            'cols' => $this->evaluate($this->cols),
            'minLength' => $this->evaluate($this->minLength),
            'maxLength' => $this->evaluate($this->maxLength),
            'autosize' => $this->evaluate($this->isAutosized) ?: null,
        ];
    }
}
