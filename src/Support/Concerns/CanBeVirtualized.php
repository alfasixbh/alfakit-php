<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;

/**
 * Windowed rendering for long lists (Repeater and Builder items, table rows): above the
 * threshold the renderer keeps only the visible items (plus a few around them) in the DOM.
 * On by default; the threshold and the switch may be closures.
 */
trait CanBeVirtualized
{
    public const DEFAULT_VIRTUALIZE_THRESHOLD = 50;

    protected bool|Closure $isVirtualized = true;

    protected int|Closure $virtualizeThreshold = self::DEFAULT_VIRTUALIZE_THRESHOLD;

    protected int|Closure|null $virtualizeEstimateSize = null;

    public function virtualize(bool|Closure $condition = true): static
    {
        $this->isVirtualized = $condition;

        return $this;
    }

    /**
     * Render only the visible window once the list has more items than this.
     */
    public function virtualizeThreshold(int|Closure $threshold): static
    {
        $this->virtualizeThreshold = $threshold;

        return $this;
    }

    /**
     * Height estimate in pixels for items the renderer has not measured yet; it affects
     * only scrollbar accuracy before the first scroll, never which items appear.
     */
    public function virtualizeEstimateSize(int|Closure|null $pixels): static
    {
        $this->virtualizeEstimateSize = $pixels;

        return $this;
    }

    public function isVirtualized(): bool
    {
        return (bool) $this->evaluate($this->isVirtualized);
    }

    public function getVirtualizeThreshold(): int
    {
        return max(0, (int) $this->evaluate($this->virtualizeThreshold));
    }

    /**
     * The `virtualize` schema prop: null when virtualisation is off.
     *
     * @return array{threshold: int, estimateSize?: int}|null
     */
    protected function getVirtualizeSchema(): ?array
    {
        if (! $this->isVirtualized()) {
            return null;
        }

        $schema = ['threshold' => $this->getVirtualizeThreshold()];
        $estimate = $this->evaluate($this->virtualizeEstimateSize);

        if ($estimate !== null) {
            $schema['estimateSize'] = max(1, (int) $estimate);
        }

        return $schema;
    }
}
