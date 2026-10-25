<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use Closure;

/**
 * Actions rendered inside a schema component (Callout, EmptyState). Pages resolve them by
 * name through `getAllActions()`, like section header actions, so they run through the
 * same authorised action endpoint. Action groups are rendered as dropdowns; their actions
 * are resolved by name like the others.
 */
trait HasEmbeddedActions
{
    use PreparesActions;

    /** @var array<Action|ActionGroup|Closure>|Closure */
    protected array|Closure $embeddedActions = [];

    /**
     * @param  array<Action|ActionGroup|Closure>|Closure  $actions
     */
    public function actions(array|Closure $actions): static
    {
        $this->embeddedActions = $actions;

        return $this;
    }

    /**
     * The configured actions and groups, bound to this component. Like section header
     * actions they get the component's form context, so `$get` / `$set` work.
     *
     * @return array<int, Action|ActionGroup>
     */
    public function getActions(): array
    {
        return $this->prepareActions($this->embeddedActions, withFieldContext: true);
    }

    /**
     * Every action (groups flattened).
     *
     * @return array<Action>
     */
    public function getAllActions(): array
    {
        return $this->flattenActions($this->getActions());
    }

    /**
     * Inside a form, the renderers run these actions (and those placed in the component's
     * mixed content) through the field action endpoint (`field:{schema}::{key}`), like a
     * section's header actions, so `$get` / `$set` reach the form state.
     *
     * @return array<string, Action>
     */
    public function getFieldActions(): array
    {
        $actions = [];

        foreach ($this->getAllActions() as $action) {
            $actions[$action->getName()] = $action;
        }

        return $actions;
    }

    public function getFieldAction(string $name): ?Action
    {
        return $this->getFieldActions()[$name] ?? null;
    }

    /**
     * @return array<array<string, mixed>>
     */
    protected function serializeEmbeddedActions(): array
    {
        return $this->serializePreparedActions($this->getActions());
    }
}
