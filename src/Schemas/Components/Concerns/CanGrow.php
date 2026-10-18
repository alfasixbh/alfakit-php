<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;

/**
 * Inside a `Flex`, components share the free space; `grow(false)` keeps one at its
 * natural width instead.
 */
trait CanGrow
{
    protected bool|Closure|null $canGrow = null;

    public function grow(bool|Closure $condition = true): static
    {
        $this->canGrow = $condition;

        return $this;
    }

    public function canGrow(): bool
    {
        return (bool) ($this->evaluate($this->canGrow) ?? true);
    }

    /**
     * `false` when growing was turned off, `null` (left out of the schema) otherwise.
     */
    protected function getGrowSchema(): ?bool
    {
        return $this->canGrow() ? null : false;
    }
}
