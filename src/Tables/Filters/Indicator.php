<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Filters;

use Stringable;

/**
 * An active-filter badge returned from `indicateUsing()`:
 *
 * ```php
 * ->indicateUsing(fn (array $data): array => array_filter([
 *     $data['from'] ? Indicator::make('Created from '.$data['from'])->removeField('from') : null,
 *     $data['until'] ? Indicator::make('Created until '.$data['until'])->removeField('until') : null,
 * ]))
 * ```
 *
 * Removing a badge clears the whole filter, or only `removeField()`; `removable(false)`
 * hides its remove button. Plain strings still work and clear the whole filter.
 */
class Indicator implements Stringable
{
    protected ?string $removeField = null;

    protected bool $isRemovable = true;

    final public function __construct(protected string $label) {}

    public static function make(string $label): static
    {
        return new static($label);
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * The field of the filter's form (relative to the filter) cleared when this badge is
     * removed, instead of the whole filter.
     */
    public function removeField(?string $field): static
    {
        $this->removeField = $field;

        return $this;
    }

    public function getRemoveField(): ?string
    {
        return $this->removeField;
    }

    public function removable(bool $condition = true): static
    {
        $this->isRemovable = $condition;

        return $this;
    }

    public function isRemovable(): bool
    {
        return $this->isRemovable;
    }

    public function __toString(): string
    {
        return $this->label;
    }
}
