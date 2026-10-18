<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * The schema inspector: a floating developer button that opens a drawer with the current
 * page's schema JSON, the identity of its context token, server timings, the payload size
 * and links that open the page and resource classes in your editor. Its data travels in
 * the `alfakitDevTools` page prop, which only exists while `app.debug` is on and the panel
 * enables it (on by default in the `local` environment). The renderers load its code only
 * on pages that carry the prop.
 *
 * ```php
 * $panel->devTools()                          // whenever app.debug is on
 * $panel->devTools(fn ($user) => $user?->isDeveloper())
 * $panel->devTools(false)                     // never, even locally
 * ```
 */
trait HasDevTools
{
    protected bool|Closure|null $hasDevTools = null;

    public function devTools(bool|Closure $condition = true): static
    {
        $this->hasDevTools = $condition;

        return $this;
    }

    /**
     * Whether this request's page carries the dev tools prop. Never when `app.debug` is
     * off, so a production deployment cannot expose it by configuration alone.
     */
    public function hasDevTools(): bool
    {
        if (! (bool) config('app.debug')) {
            return false;
        }

        $enabled = $this->evaluate($this->hasDevTools);

        return $enabled === null ? app()->environment('local') : (bool) $enabled;
    }
}
