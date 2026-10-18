<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use Closure;

trait CanBeReadOnly
{
    protected bool|Closure $isReadOnly = false;

    public function readOnly(bool|Closure $condition = true): static
    {
        $this->isReadOnly = $condition;

        return $this;
    }

    public function isReadOnly(): bool
    {
        if ($this->evaluate($this->isReadOnly)) {
            return true;
        }

        // Fields inside a read-only Repeater (or any read-only parent field) are read-only too.
        if (method_exists($this, 'getContainer') && $this->hasContainer()) {
            $parent = $this->getContainer()->getParentComponent();

            if ($parent !== null && method_exists($parent, 'isReadOnly') && $parent->isReadOnly()) {
                return true;
            }
        }

        return false;
    }
}
