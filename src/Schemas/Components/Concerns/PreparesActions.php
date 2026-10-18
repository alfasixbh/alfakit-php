<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use Closure;

/**
 * Resolves a list of actions and action groups configured on a schema component
 * (`headerActions()`, `actions()`…). A closure may stand for the whole list or for single
 * items, and may return one action, a group or an array of them. Every action (also inside
 * groups) is bound to the component, so pages resolve it by name.
 */
trait PreparesActions
{
    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function prepareActions(mixed $actions, bool $withFieldContext = false): array
    {
        $actions = $actions instanceof Closure ? $this->evaluate($actions) : $actions;
        $prepared = [];

        foreach (is_array($actions) ? $actions : [$actions] as $action) {
            if ($action instanceof Closure) {
                array_push($prepared, ...$this->prepareActions($this->evaluate($action), $withFieldContext));

                continue;
            }

            if (is_array($action)) {
                array_push($prepared, ...$this->prepareActions($action, $withFieldContext));

                continue;
            }

            if ($action instanceof ActionGroup) {
                $prepared[] = $this->prepareActionGroup($action, $withFieldContext);
            } elseif ($action instanceof Action) {
                $prepared[] = $this->prepareAction($action, $withFieldContext);
            }
        }

        return $prepared;
    }

    protected function prepareAction(Action $action, bool $withFieldContext = false): Action
    {
        $action->schemaComponent($this);

        return $withFieldContext ? $action->fieldComponent($this) : $action;
    }

    public function prepareActionGroup(ActionGroup $group, bool $withFieldContext = false): ActionGroup
    {
        $group->schemaComponent($this);

        return $withFieldContext ? $group->fieldComponent($this) : $group;
    }

    /**
     * @param  array<int, Action|ActionGroup>  $actions
     * @return array<int, Action>
     */
    protected function flattenActions(array $actions): array
    {
        $flat = [];

        foreach ($actions as $action) {
            array_push($flat, ...($action instanceof ActionGroup ? $action->getFlatActions() : [$action]));
        }

        return $flat;
    }

    /**
     * Visible actions and groups as schema arrays.
     *
     * @param  array<int, Action|ActionGroup>  $actions
     * @return array<int, array<string, mixed>>
     */
    protected function serializePreparedActions(array $actions): array
    {
        $serialized = [];

        foreach ($actions as $action) {
            if ($action->isVisible()) {
                $serialized[] = $action->toSchema();
            }
        }

        return $serialized;
    }
}
