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
        // Channels 0–255, hue 0–360, percentages 0–100: what the picker itself can produce.
        $channel = '(25[0-5]|2[0-4]\d|1?\d?\d)';
        $percent = '(100|[1-9]?\d)%';
        $hue = '(360|3[0-5]\d|[12]?\d?\d)';

        return [match ($this->getFormat()) {
            'rgb' => "regex:/^rgb\(\s*{$channel}\s*,\s*{$channel}\s*,\s*{$channel}\s*\)$/i",
            'rgba' => "regex:/^rgba\(\s*{$channel}\s*,\s*{$channel}\s*,\s*{$channel}\s*,\s*(0|1|0?\.\d+|1\.0+)\s*\)$/i",
            'hsl' => "regex:/^hsl\(\s*{$hue}\s*,\s*{$percent}\s*,\s*{$percent}\s*\)$/i",
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
