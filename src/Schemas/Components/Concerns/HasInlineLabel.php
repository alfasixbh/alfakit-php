<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;

/**
 * `inlineLabel()`: show the label beside the control (from the `sm` breakpoint up) instead
 * of above it. Set on a field or entry, or on a layout component (Section, Grid, Fieldset…)
 * or a whole `Schema` to apply to everything inside; a child's own setting wins, so
 * `inlineLabel(false)` opts one field out.
 */
trait HasInlineLabel
{
    protected bool|Closure|null $hasInlineLabel = null;

    public function inlineLabel(bool|Closure|null $condition = true): static
    {
        $this->hasInlineLabel = $condition;

        return $this;
    }

    public function hasInlineLabel(): bool
    {
        $condition = $this->evaluate($this->hasInlineLabel);

        if ($condition !== null) {
            return (bool) $condition;
        }

        return $this->hasContainer() && $this->getContainer()->hasInlineLabel();
    }
}
