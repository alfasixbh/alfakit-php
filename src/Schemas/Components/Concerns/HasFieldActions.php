<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use Closure;
use Illuminate\Support\Arr;

/**
 * Actions rendered with a field or entry: before (`prefixActions`) and after
 * (`suffixActions`) the control, and next to the label (`hintActions`). They run through
 * the action endpoint in the component's context (see `InteractsWithFieldContext`): the
 * renderer sends the form's current state, and the response carries the refreshed form,
 * so `$set()` inside an action updates the form in the browser.
 */
trait HasFieldActions
{
    /** @var array<Action|Closure> */
    protected array $prefixActions = [];

    /** @var array<Action|Closure> */
    protected array $suffixActions = [];

    /** @var array<Action|Closure> */
    protected array $hintActions = [];

    public function prefixAction(Action|Closure|null $action): static
    {
        if ($action !== null) {
            $this->prefixActions[] = $action;
        }

        return $this;
    }

    /**
     * @param  array<Action|Closure>|Closure  $actions
     */
    public function prefixActions(array|Closure $actions): static
    {
        $this->prefixActions = [...$this->prefixActions, ...Arr::wrap($actions)];

        return $this;
    }

    public function suffixAction(Action|Closure|null $action): static
    {
        if ($action !== null) {
            $this->suffixActions[] = $action;
        }

        return $this;
    }

    /**
     * @param  array<Action|Closure>|Closure  $actions
     */
    public function suffixActions(array|Closure $actions): static
    {
        $this->suffixActions = [...$this->suffixActions, ...Arr::wrap($actions)];

        return $this;
    }

    public function hintAction(Action|Closure|null $action): static
    {
        if ($action !== null) {
            $this->hintActions[] = $action;
        }

        return $this;
    }

    /**
     * @param  array<Action|Closure>|Closure  $actions
     */
    public function hintActions(array|Closure $actions): static
    {
        $this->hintActions = [...$this->hintActions, ...Arr::wrap($actions)];

        return $this;
    }

    /**
     * @return array<Action>
     */
    public function getPrefixActions(): array
    {
        return $this->resolveFieldActions($this->prefixActions);
    }

    /**
     * @return array<Action>
     */
    public function getSuffixActions(): array
    {
        return $this->resolveFieldActions($this->suffixActions);
    }

    /**
     * @return array<Action>
     */
    public function getHintActions(): array
    {
        return $this->resolveFieldActions($this->hintActions);
    }

    /**
     * Actions the component runs without rendering them as buttons (e.g. a rich editor's
     * custom block modal). Override in components that need them.
     *
     * @return array<Action>
     */
    protected function getExtraFieldActions(): array
    {
        return [];
    }

    /**
     * Every action of the component, keyed by name, bound to the component.
     *
     * @return array<string, Action>
     */
    public function getFieldActions(): array
    {
        $actions = [];

        // Actions placed in content slots (`belowContent(Action::make(...))`) run here too.
        $slotActions = method_exists($this, 'getSlotActions') ? $this->getSlotActions() : [];

        foreach ([...$this->getPrefixActions(), ...$this->getSuffixActions(), ...$this->getHintActions(), ...$slotActions, ...$this->getExtraFieldActions()] as $action) {
            $actions[$action->getName()] = $this->prepareFieldAction($action);
        }

        return $actions;
    }

    public function getFieldAction(string $name): ?Action
    {
        return $this->getFieldActions()[$name] ?? null;
    }

    /**
     * @param  array<Action|Closure>  $actions
     * @return array<Action>
     */
    protected function resolveFieldActions(array $actions): array
    {
        $resolved = [];

        foreach ($actions as $action) {
            foreach (Arr::wrap($this->evaluate($action)) as $item) {
                if ($item instanceof Action) {
                    $resolved[] = $this->prepareFieldAction($item);
                }
            }
        }

        return $resolved;
    }

    protected function prepareFieldAction(Action $action): Action
    {
        return $action->schemaComponent($this)->fieldComponent($this);
    }

    /**
     * @return array<string, array<int, array<string, mixed>>|null>
     */
    protected function getFieldActionsSchema(): array
    {
        $disabled = method_exists($this, 'isDisabled') && $this->isDisabled();

        $serialize = static function (array $actions) use ($disabled): ?array {
            $schemas = [];

            foreach ($actions as $action) {
                if (! $action->isVisible()) {
                    continue;
                }

                $schema = $action->toSchema();

                if ($disabled) {
                    $schema['disabled'] = true;
                }

                $schemas[] = $schema;
            }

            return $schemas === [] ? null : $schemas;
        };

        return [
            'prefixActions' => $serialize($this->getPrefixActions()),
            'suffixActions' => $serialize($this->getSuffixActions()),
            'hintActions' => $serialize($this->getHintActions()),
        ];
    }
}
