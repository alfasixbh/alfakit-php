<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use Closure;

/**
 * Panel-wide defaults for resource relation managers.
 */
trait HasRelationManagerOptions
{
    protected bool|Closure $hasReadOnlyRelationManagersOnResourceViewPagesByDefault = true;

    /**
     * Whether relation managers on resource View pages hide their create, edit, delete,
     * attach… actions. On by default; a manager's own `isReadOnly()` override still wins.
     */
    public function readOnlyRelationManagersOnResourceViewPagesByDefault(bool|Closure $condition = true): static
    {
        $this->hasReadOnlyRelationManagersOnResourceViewPagesByDefault = $condition;

        return $this;
    }

    public function hasReadOnlyRelationManagersOnResourceViewPagesByDefault(): bool
    {
        return (bool) $this->evaluate($this->hasReadOnlyRelationManagersOnResourceViewPagesByDefault);
    }
}
