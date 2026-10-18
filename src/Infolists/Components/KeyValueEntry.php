<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use Closure;

class KeyValueEntry extends Entry
{
    protected string|Closure|null $keyLabel = null;

    protected string|Closure|null $valueLabel = null;

    public function keyLabel(string|Closure|null $label): static
    {
        $this->keyLabel = $label;

        return $this;
    }

    public function valueLabel(string|Closure|null $label): static
    {
        $this->valueLabel = $label;

        return $this;
    }

    protected function getFormattedState(): mixed
    {
        $state = $this->getState();

        if ($state instanceof \Illuminate\Support\Collection) {
            $state = $state->all();
        }

        return is_array($state) ? $state : [];
    }

    protected function getSchemaProps(): array
    {
        return [
            'keyLabel' => $this->evaluate($this->keyLabel) ?? __('alfakit::forms.key_value.key'),
            'valueLabel' => $this->evaluate($this->valueLabel) ?? __('alfakit::forms.key_value.value'),
        ];
    }
}
