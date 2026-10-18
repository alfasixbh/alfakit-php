<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use Closure;

class ColorPicker extends Field
{
    protected string|Closure $format = 'hex';

    public function hex(): static
    {
        return $this->format('hex');
    }

    public function rgb(): static
    {
        return $this->format('rgb');
    }

    public function rgba(): static
    {
        return $this->format('rgba');
    }

    public function hsl(): static
    {
        return $this->format('hsl');
    }

    public function format(string|Closure $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function getFormat(): string
    {
        return $this->evaluate($this->format);
    }

    protected function getTypeValidationRules(): array
    {
        return [match ($this->getFormat()) {
            'rgb' => 'regex:/^rgb\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*\)$/i',
            'rgba' => 'regex:/^rgba\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*(0|1|0?\.\d+)\s*\)$/i',
            'hsl' => 'regex:/^hsl\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*\)$/i',
            default => 'hex_color',
        }];
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        return $state === '' ? null : $state;
    }

    protected function getSchemaProps(): array
    {
        return ['format' => $this->getFormat()];
    }
}
