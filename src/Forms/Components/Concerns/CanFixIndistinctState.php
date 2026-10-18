<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Forms\Components\Repeater;
use Closure;

/**
 * `fixIndistinctState()` for a field inside a Repeater (or Builder) item: when it changes,
 * the same field in the other items is reset so the value stays distinct. A boolean
 * turned on turns the others off ("only one primary address"); any other value clears
 * the items holding the same value. Pair it with `distinct()` to validate the rule too.
 *
 * Used by fields whose class (or parent) defines `callAfterStateUpdated()`.
 */
trait CanFixIndistinctState
{
    protected bool|Closure $shouldFixIndistinctState = false;

    public function fixIndistinctState(bool|Closure $condition = true): static
    {
        $this->shouldFixIndistinctState = $condition;

        if ($condition !== false) {
            $this->live();
        }

        return $this;
    }

    public function shouldFixIndistinctState(): bool
    {
        return (bool) $this->evaluate($this->shouldFixIndistinctState);
    }

    public function callAfterStateUpdated(mixed $old = null): void
    {
        parent::callAfterStateUpdated($old);

        if ($this->shouldFixIndistinctState()) {
            $this->fixIndistinctSiblingState();
        }
    }

    /**
     * Reset the same field in the other items of the repeater this field's item is in.
     */
    public function fixIndistinctSiblingState(): void
    {
        $item = Repeater::findItemOf($this);

        if ($item === null || $item[1] === null) {
            return;
        }

        [$repeater, $index, $relativePath] = $item;
        $state = $this->getState();

        if ($state === null || $state === false || $state === '' || $state === []) {
            return;
        }

        foreach ($repeater->getSiblingItemStates($index, $relativePath) as $siblingIndex => $siblingState) {
            if ($state === true) {
                if ($siblingState === true || $siblingState === 1 || $siblingState === '1') {
                    $repeater->setItemStateAt($siblingIndex, $relativePath, false);
                }
            } elseif (is_scalar($siblingState) && is_scalar($state) && (string) $siblingState === (string) $state) {
                $repeater->setItemStateAt($siblingIndex, $relativePath, null);
            }
        }
    }
}
