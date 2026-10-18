<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * The developer debug panel: a drawer in the top bar showing the resolved schema tree
 * (visibility and authorisation per component, closures evaluated), the form state, the
 * latest internal request and timings. Its data travels in the `alfakitDebug` page prop,
 * which only exists while the panel is active: `app.debug` must be on and the panel must
 * enable it (on by default in the `local` environment).
 *
 * ```php
 * $panel->debugPanel()                          // on whenever app.debug is on
 * $panel->debugPanel(fn ($user) => $user?->isDeveloper())
 * $panel->debugPanel(false)                     // never, even locally
 * ```
 */
trait HasDebugPanel
{
    protected bool|Closure|null $hasDebugPanel = null;

    public function debugPanel(bool|Closure $condition = true): static
    {
        $this->hasDebugPanel = $condition;

        return $this;
    }

    /**
     * Whether the debug prop is added to this request's page. Never when `app.debug` is off,
     * so a production deployment cannot expose it by configuration alone.
     */
    public function hasDebugPanel(): bool
    {
        if (! (bool) config('app.debug')) {
            return false;
        }

        $enabled = $this->evaluate($this->hasDebugPanel);

        return $enabled === null ? app()->environment('local') : (bool) $enabled;
    }
}
