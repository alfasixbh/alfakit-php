<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use AlfaSix\Alfakit\Support\Credit;
use Closure;

/**
 * `->poweredBy()` for plugins with customer-facing pages: shows the "Powered by AlfaKit ·
 * Alfa Six" line there. Unset, it follows `config('alfakit.credit.public_pages')` (pages)
 * and `config('alfakit.credit.emails')` (emails), both off by default.
 */
trait HasPoweredByCredit
{
    protected bool|Closure|null $poweredBy = null;

    public function poweredBy(bool|Closure|null $condition = true): static
    {
        $this->poweredBy = $condition;

        return $this;
    }

    /**
     * Whether the plugin's public pages show the line.
     */
    public function hasPoweredBy(): bool
    {
        return $this->resolvePoweredBy() ?? Credit::onPublicPages();
    }

    /**
     * Whether the plugin's emails end with the line.
     */
    public function hasPoweredByInEmails(): bool
    {
        return $this->resolvePoweredBy() ?? Credit::inEmails();
    }

    /**
     * The line as HTML for the plugin's public pages, or null when it's off. `$embedded`
     * suits documents rendered by PDF engines (see `Credit::poweredBy()`).
     */
    public function getPoweredByHtml(bool $embedded = false): ?string
    {
        return Credit::poweredByWhen($this->hasPoweredBy(), $embedded);
    }

    /**
     * The line as Markdown for the plugin's emails, or null when it's off.
     */
    public function getPoweredByEmailLine(): ?string
    {
        return Credit::emailLine($this->hasPoweredByInEmails());
    }

    protected function resolvePoweredBy(): ?bool
    {
        $value = $this->poweredBy instanceof Closure ? app()->call($this->poweredBy) : $this->poweredBy;

        return $value === null ? null : (bool) $value;
    }
}
