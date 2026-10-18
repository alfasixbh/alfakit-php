<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;

trait HasExtraAttributes
{
    /** @var array<array<string, mixed>|Closure> */
    protected array $extraAttributes = [];

    /**
     * Attach arbitrary HTML attributes (e.g. `data-*`, `class`) to the rendered element.
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($merge) {
            $this->extraAttributes[] = $attributes;
        } else {
            $this->extraAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraAttributes(): array
    {
        $temporaryAttributes = [];

        foreach ($this->extraAttributes as $extraAttributes) {
            $temporaryAttributes = array_merge($temporaryAttributes, $this->evaluate($extraAttributes) ?? []);
        }

        return $temporaryAttributes;
    }
}
