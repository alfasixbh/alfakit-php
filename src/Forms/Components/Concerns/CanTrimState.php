<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use Closure;

/**
 * `trim()`: remove whitespace from the start and end of the value before it is validated
 * and saved. The browser keeps what the user typed; only the server sees the trimmed value.
 */
trait CanTrimState
{
    protected bool|Closure $isTrimmed = false;

    public function trim(bool|Closure $condition = true): static
    {
        $this->isTrimmed = $condition;

        return $this;
    }

    public function isTrimmed(): bool
    {
        return (bool) $this->evaluate($this->isTrimmed);
    }

    /**
     * Trim a string, or every string in a list (tags). Other values pass through.
     */
    protected function trimState(mixed $state): mixed
    {
        if (! $this->isTrimmed()) {
            return $state;
        }

        if (is_string($state)) {
            return trim($state);
        }

        if (is_array($state)) {
            return array_map(static fn (mixed $item): mixed => is_string($item) ? trim($item) : $item, $state);
        }

        return $state;
    }
}
