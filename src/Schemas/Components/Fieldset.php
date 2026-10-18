<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use Closure;

class Fieldset extends Group
{
    protected bool|Closure $isContained = true;

    public function __construct(string|Closure|null $label = null)
    {
        $this->label($label);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        $this->columns(['default' => 1, 'lg' => 2]);
        $this->columnSpanFull();
    }

    /**
     * `false` removes the border and padding around the fields (the legend stays).
     */
    public function contained(bool|Closure $condition = true): static
    {
        $this->isContained = $condition;

        return $this;
    }

    public function isContained(): bool
    {
        return (bool) $this->evaluate($this->isContained);
    }

    protected function getSchemaProps(): array
    {
        return [
            ...parent::getSchemaProps(),
            'contained' => $this->isContained() ? null : false,
        ];
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }
}
