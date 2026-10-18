<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Concerns;

use AlfaSix\Alfakit\Tables\Columns\Summarizers\Summarizer;
use Closure;

/**
 * Column summaries shown below the records (and below each row group).
 */
trait HasSummarizers
{
    /** @var array<Summarizer>|Summarizer|Closure */
    protected array|Summarizer|Closure $summarizers = [];

    /**
     * @param  array<Summarizer>|Summarizer|Closure  $summarizers
     */
    public function summarize(array|Summarizer|Closure $summarizers): static
    {
        $this->summarizers = $summarizers;

        return $this;
    }

    /**
     * @return array<Summarizer>
     */
    public function getSummarizers(): array
    {
        $summarizers = $this->evaluate($this->summarizers) ?? [];
        $summarizers = $summarizers instanceof Summarizer ? [$summarizers] : $summarizers;

        $visible = [];

        foreach ($summarizers as $summarizer) {
            $summarizer->column($this);

            if (! $summarizer->isHidden()) {
                $visible[] = $summarizer;
            }
        }

        return $visible;
    }

    public function hasSummary(): bool
    {
        return $this->getSummarizers() !== [];
    }
}
