<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Concerns;

use Closure;

/**
 * The heading level (`h2`…`h6`) of the top-level sections inside a schema or a layout
 * component. Nested sections go one level deeper each; sections find the nearest
 * schema or component that sets it (2 when none does).
 *
 * ```php
 * $schema->rootHeadingLevel(3)
 * Group::make([...])->rootHeadingLevel(4)
 * ```
 */
trait HasRootHeadingLevel
{
    protected int|Closure|null $rootHeadingLevel = null;

    public function rootHeadingLevel(int|Closure|null $level): static
    {
        $this->rootHeadingLevel = $level;

        return $this;
    }

    public function getRootHeadingLevel(): ?int
    {
        $level = $this->evaluate($this->rootHeadingLevel);

        return $level === null ? null : max(1, min(6, (int) $level));
    }
}
