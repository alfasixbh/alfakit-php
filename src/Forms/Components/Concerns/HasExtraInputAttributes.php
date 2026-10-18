<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use Closure;

trait HasExtraInputAttributes
{
    /** @var array<array<string, mixed>|Closure> */
    protected array $extraInputAttributes = [];

    /**
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraInputAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($merge) {
            $this->extraInputAttributes[] = $attributes;
        } else {
            $this->extraInputAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraInputAttributes(): array
    {
        $attributes = [];

        foreach ($this->extraInputAttributes as $extra) {
            $attributes = array_merge($attributes, $this->evaluate($extra) ?? []);
        }

        return $attributes;
    }
}
