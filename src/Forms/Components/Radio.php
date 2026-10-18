<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Support\Concerns\HasOptions;
use Closure;
use Illuminate\Validation\Rule;

class Radio extends Field
{
    use Concerns\CanFixIndistinctState;
    use HasOptions;

    protected bool|Closure $isInline = false;

    protected bool|Closure $isBoolean = false;

    public function inline(bool|Closure $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    public function boolean(?string $trueLabel = null, ?string $falseLabel = null): static
    {
        $this->isBoolean = true;

        return $this->options([
            1 => $trueLabel ?? __('alfakit::forms.select.boolean.true'),
            0 => $falseLabel ?? __('alfakit::forms.select.boolean.false'),
        ]);
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if ($state === '' || $state === null) {
            return null;
        }

        return $this->evaluate($this->isBoolean) ? (bool) $state : $state;
    }

    protected function getTypeValidationRules(): array
    {
        $options = array_keys($this->getFlatOptions());

        return $options === [] ? [] : [Rule::in($options)];
    }

    /**
     * Options built from an enum show its `HasDescription` descriptions.
     */
    protected function getEnumOptionDetails(): array
    {
        return ['description'];
    }

    protected function getSchemaProps(): array
    {
        return [
            'options' => $this->getSerializedOptions(),
            'inline' => $this->evaluate($this->isInline) ?: null,
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
