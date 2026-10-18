<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use Closure;

/**
 * Deferred filters wait for an "Apply" button instead of reloading the table on every
 * change; useful for expensive queries and for the query builder.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait CanDeferFilters
{
    protected bool|Closure $hasDeferredFilters = false;

    public function deferFilters(bool|Closure $condition = true): static
    {
        $this->hasDeferredFilters = $condition;

        return $this;
    }

    public function hasDeferredFilters(): bool
    {
        return (bool) $this->evaluate($this->hasDeferredFilters);
    }
}
