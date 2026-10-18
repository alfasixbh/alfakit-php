<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * URLs that load with a full page request instead of an Inertia visit (pages that need a
 * fresh document: third-party scripts, other apps on the same domain…).
 */
trait HasSpaUrlExceptions
{
    /** @var array<string>|Closure */
    protected array|Closure $spaUrlExceptions = [];

    /**
     * Absolute URLs or patterns where `*` matches anything:
     * `[url('/reports/live'), url('/exports').'*']`.
     *
     * @param  array<string>|Closure  $exceptions
     */
    public function spaUrlExceptions(array|Closure $exceptions): static
    {
        $this->spaUrlExceptions = $exceptions;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getSpaUrlExceptions(): array
    {
        $exceptions = $this->evaluate($this->spaUrlExceptions) ?? [];

        return array_values(array_filter(array_map(static fn (mixed $url): string => is_string($url) ? trim($url) : '', $exceptions), static fn (string $url): bool => $url !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSpaOptionsSchema(): array
    {
        return [
            // `spa(false)`: every link loads a full page.
            'spa' => $this->hasSpaMode ? null : false,
            'spaUrlExceptions' => $this->hasSpaMode ? ($this->getSpaUrlExceptions() ?: null) : null,
        ];
    }
}
