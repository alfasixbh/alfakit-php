<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * By default a model without a policy, or a policy without the checked method, allows
 * the ability. `strictAuthorization()` denies instead, so forgetting a policy method
 * never opens access.
 */
trait HasStrictAuthorization
{
    protected bool|Closure $hasStrictAuthorization = false;

    public function strictAuthorization(bool|Closure $condition = true): static
    {
        $this->hasStrictAuthorization = $condition;

        return $this;
    }

    public function hasStrictAuthorization(): bool
    {
        return (bool) $this->evaluate($this->hasStrictAuthorization);
    }
}
