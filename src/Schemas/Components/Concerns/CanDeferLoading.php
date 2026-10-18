<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Schemas\DeferredLoading;
use Closure;

/**
 * `deferLoading()`: the page's first response sends the component without its children
 * (a skeleton in the browser); the renderer loads them from the deferred-schema endpoint
 * once the skeleton scrolls into view, or right away when one of its fields has a
 * validation error.
 */
trait CanDeferLoading
{
    protected bool|Closure $isLoadingDeferred = false;

    public function deferLoading(bool|Closure $condition = true): static
    {
        $this->isLoadingDeferred = $condition;

        return $this;
    }

    public function isLoadingDeferred(): bool
    {
        return (bool) $this->evaluate($this->isLoadingDeferred);
    }

    public function shouldRenderDeferredPlaceholder(): bool
    {
        return DeferredLoading::rendersPlaceholders() && $this->isLoadingDeferred();
    }
}
