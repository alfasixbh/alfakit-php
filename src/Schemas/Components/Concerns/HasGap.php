<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;

/**
 * Spacing between a layout component's children: `dense()` halves the gap, `gap(false)`
 * removes it. Only serialised for components with children.
 */
trait HasGap
{
    protected bool|Closure $isDense = false;

    protected bool|Closure $hasGap = true;

    public function dense(bool|Closure $condition = true): static
    {
        $this->isDense = $condition;

        return $this;
    }

    public function gap(bool|Closure $condition = true): static
    {
        $this->hasGap = $condition;

        return $this;
    }

    public function isDense(): bool
    {
        return (bool) $this->evaluate($this->isDense);
    }

    public function hasGap(): bool
    {
        return (bool) $this->evaluate($this->hasGap);
    }
}
