<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use Closure;

/**
 * HTML attributes for the element wrapping the field's label, control, helper text and
 * error (`data-slot="field"`). Renderers merge them with `extraAttributes()`, joining the
 * `class` values instead of replacing them.
 */
trait HasExtraFieldWrapperAttributes
{
    /** @var array<array<string, mixed>|Closure> */
    protected array $extraFieldWrapperAttributes = [];

    /**
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraFieldWrapperAttributes(array|Closure $attributes, bool $merge = false): static
    {
        if ($merge) {
            $this->extraFieldWrapperAttributes[] = $attributes;
        } else {
            $this->extraFieldWrapperAttributes = [$attributes];
        }

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraFieldWrapperAttributes(): array
    {
        $attributes = [];

        foreach ($this->extraFieldWrapperAttributes as $extraAttributes) {
            $attributes = array_merge($attributes, $this->evaluate($extraAttributes) ?? []);
        }

        return $attributes;
    }
}
