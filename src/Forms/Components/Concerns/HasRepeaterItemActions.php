<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Forms\Components\Repeater;
use AlfaSix\Alfakit\Forms\Components\Repeater\ItemAction;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Labels;
use Closure;
use Illuminate\Support\Arr;

/**
 * The item actions of a Repeater (and Builder), customisable like Filament's:
 *
 * ```php
 * Repeater::make('members')
 *     ->deleteAction(fn (Action $action) => $action->requiresConfirmation())
 *     ->extraItemActions([
 *         Action::make('sendEmail')->icon('mail')->action(function (array $arguments, Repeater $component): void {
 *             $member = $component->getItemState($arguments['item']);
 *         }),
 *     ]);
 * ```
 *
 * `$arguments['item']` is the item key: its position in the list (`'0'`, `'1'` …).
 * Reorder actions get `$arguments['items']` (the keys in their new order) and the
 * Builder's add action `$arguments['block']`.
 *
 * @mixin Repeater
 */
trait HasRepeaterItemActions
{
    use CanCustomizeItemActions;

    /** Actions shown once per field. */
    public const FIELD_ACTIONS = ['add', 'reorder', 'collapseAll', 'expandAll'];

    /** Actions shown on every item; their closures receive `$arguments['item']`. */
    public const ITEM_ACTIONS = ['moveUp', 'moveDown', 'clone', 'delete', 'collapse', 'expand'];

    /** @var array<Action|Closure> */
    protected array $extraItemActions = [];

    protected bool|Closure $isReorderableWithButtons = true;

    protected bool|Closure $isReorderableWithDragAndDrop = true;

    protected Alignment|string|Closure|null $addActionAlignment = null;

    protected bool|Closure $hasItemNumbers = false;

    // ---------------------------------------------------------------- Configuration

    public function addAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('add', $callback);
    }

    public function deleteAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('delete', $callback);
    }

    public function cloneAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('clone', $callback);
    }

    /**
     * The drag handle. Runs with `$arguments['items']`, the item keys in their new order.
     */
    public function reorderAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('reorder', $callback);
    }

    public function moveUpAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('moveUp', $callback);
    }

    public function moveDownAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('moveDown', $callback);
    }

    public function collapseAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('collapse', $callback);
    }

    public function expandAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('expand', $callback);
    }

    public function collapseAllAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('collapseAll', $callback);
    }

    public function expandAllAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('expandAll', $callback);
    }

    /**
     * More actions in each item's header. They run on the server with `$arguments['item']`
     * and may change the field with `$component->state()` or `$set()`.
     *
     * @param  array<Action|Closure>|Closure  $actions
     */
    public function extraItemActions(array|Closure $actions): static
    {
        $this->extraItemActions = [...$this->extraItemActions, ...Arr::wrap($actions)];

        return $this;
    }

    /**
     * Move up / move down buttons in each item's header (on by default, as they are the
     * keyboard way to reorder).
     */
    public function reorderableWithButtons(bool|Closure $condition = true): static
    {
        $this->isReorderableWithButtons = $condition;

        return $this->reorderable();
    }

    /**
     * Reorder by dragging items by their handle (on by default).
     */
    public function reorderableWithDragAndDrop(bool|Closure $condition = true): static
    {
        $this->isReorderableWithDragAndDrop = $condition;

        return $this;
    }

    /**
     * Where the add button sits under the items: `Alignment::Start` (default),
     * `Alignment::Center` or `Alignment::End`.
     */
    public function addActionAlignment(Alignment|string|Closure|null $alignment): static
    {
        $this->addActionAlignment = $alignment;

        return $this;
    }

    /**
     * Number the items in their headers (`1`, `2` …).
     */
    public function itemNumbers(bool|Closure $condition = true): static
    {
        $this->hasItemNumbers = $condition;

        return $this;
    }

    // ---------------------------------------------------------------- Getters

    public function isReorderableWithButtons(): bool
    {
        return (bool) $this->evaluate($this->isReorderable) && (bool) $this->evaluate($this->isReorderableWithButtons);
    }

    public function isReorderableWithDragAndDrop(): bool
    {
        return (bool) $this->evaluate($this->isReorderable) && (bool) $this->evaluate($this->isReorderableWithDragAndDrop);
    }

    public function getAddActionAlignment(): ?string
    {
        $alignment = $this->evaluate($this->addActionAlignment);

        return $alignment instanceof Alignment ? $alignment->value : $alignment;
    }

    public function hasItemNumbers(): bool
    {
        return (bool) $this->evaluate($this->hasItemNumbers);
    }

    public function getAddAction(): ?ItemAction
    {
        return $this->getItemAction('add');
    }

    public function getDeleteAction(): ?ItemAction
    {
        return $this->getItemAction('delete');
    }

    public function getCloneAction(): ?ItemAction
    {
        return $this->getItemAction('clone');
    }

    public function getReorderAction(): ?ItemAction
    {
        return $this->getItemAction('reorder');
    }

    public function getMoveUpAction(): ?ItemAction
    {
        return $this->getItemAction('moveUp');
    }

    public function getMoveDownAction(): ?ItemAction
    {
        return $this->getItemAction('moveDown');
    }

    public function getCollapseAction(): ?ItemAction
    {
        return $this->getItemAction('collapse');
    }

    public function getExpandAction(): ?ItemAction
    {
        return $this->getItemAction('expand');
    }

    public function getCollapseAllAction(): ?ItemAction
    {
        return $this->getItemAction('collapseAll');
    }

    public function getExpandAllAction(): ?ItemAction
    {
        return $this->getItemAction('expandAll');
    }

    /**
     * @return array<Action>
     */
    public function getExtraItemActions(): array
    {
        return $this->resolveFieldActions($this->extraItemActions);
    }

    /**
     * Item actions run through the field action endpoint like the field's other actions.
     *
     * @return array<Action>
     */
    protected function getExtraFieldActions(): array
    {
        $actions = [];

        foreach ($this->getItemActionNames() as $name) {
            if (($action = $this->getItemAction($name)) !== null) {
                $actions[] = $action;
            }
        }

        return [...$actions, ...$this->getExtraItemActions()];
    }

    // ---------------------------------------------------------------- Built-in actions

    protected function getItemActionNames(): array
    {
        return [...self::FIELD_ACTIONS, ...self::ITEM_ACTIONS];
    }

    /**
     * Whether the field's flags allow the action. Item counts (`minItems()`,
     * `maxItems()`) are checked when it runs, as the browser changes them without asking.
     */
    protected function isItemActionEnabled(string $name): bool
    {
        if ($this->isReadOnly()) {
            return false;
        }

        return match ($name) {
            'add' => (bool) $this->evaluate($this->isAddable),
            'delete' => (bool) $this->evaluate($this->isDeletable),
            'clone' => (bool) $this->evaluate($this->isCloneable) && (bool) $this->evaluate($this->isAddable),
            'reorder' => $this->isReorderableWithDragAndDrop(),
            'moveUp', 'moveDown' => $this->isReorderableWithButtons(),
            'collapse', 'expand', 'collapseAll', 'expandAll' => (bool) $this->evaluate($this->isCollapsible),
            default => false,
        };
    }

    protected function makeItemAction(string $name): ItemAction
    {
        $action = ItemAction::make($name)->color('gray');

        return match ($name) {
            'add' => $action
                ->label(fn (): string => $this->getDefaultAddActionLabel())
                ->icon('plus')
                ->outlined()
                ->defaultAction(function (array $arguments): void {
                    $this->addItemFromArguments($arguments);
                }),
            'delete' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.delete', 'Delete'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.delete')
                ->color('danger')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->deleteItem($arguments['item'] ?? null);
                }),
            'clone' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.clone', 'Clone'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.clone')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->cloneItem($arguments['item'] ?? null);
                }),
            'moveUp' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.move_up', 'Move up'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.move-up')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->moveItem($arguments['item'] ?? null, -1);
                }),
            'moveDown' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.move_down', 'Move down'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.move-down')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->moveItem($arguments['item'] ?? null, 1);
                }),
            'reorder' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.reorder', 'Reorder'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.reorder')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->reorderItems(is_array($arguments['items'] ?? null) ? $arguments['items'] : []);
                }),
            'collapse' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.collapse', 'Collapse'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.collapse')
                ->iconButton()
                ->clientOnly(),
            'expand' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.expand', 'Expand'))
                ->icon('alfakit::forms.components.'.($this instanceof \AlfaSix\Alfakit\Forms\Components\Builder ? 'builder' : 'repeater').'.actions.expand')
                ->iconButton()
                ->clientOnly(),
            'collapseAll' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.collapse_all', 'Collapse all'))
                ->icon('chevrons-down-up')
                ->link()
                ->clientOnly(),
            'expandAll' => $action
                ->label(self::itemActionLabel('alfakit::forms.repeater.expand_all', 'Expand all'))
                ->icon('chevrons-up-down')
                ->link()
                ->clientOnly(),
            default => $action,
        };
    }

    protected function getDefaultAddActionLabel(): string
    {
        return $this->evaluate($this->addActionLabel) ?? __('alfakit::forms.repeater.add', ['label' => Labels::forSentence($this->getLabel() ?? '')]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function addItemFromArguments(array $arguments): void
    {
        $this->addItem();
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * The customised built-in actions for the renderer, keyed by name (the renderer draws
     * the others itself, from the field's switches). Item actions are evaluated with
     * `$arguments['item']` set to null here (they also stand for items the browser added);
     * customised ones are evaluated again per item (`getItemActionsSchemaFor()`). A hidden
     * action is null.
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    protected function getFieldItemActionsSchema(): ?array
    {
        $schemas = [];

        foreach ($this->getItemActionNames() as $name) {
            // Uncustomised actions are left to the renderer's defaults (smaller payloads).
            if (! isset($this->itemActionModifiers[$name]) || ($action = $this->getItemAction($name)) === null) {
                continue;
            }

            if (in_array($name, self::ITEM_ACTIONS, true)) {
                $action->arguments(['item' => null]);
            }

            $schemas[$name] = $action->isVisible() ? $action->toSchema() : null;
        }

        return $schemas === [] ? null : $schemas;
    }

    /**
     * Per-item schemas of the customised item actions (null when hidden for the item).
     *
     * @return array<string, array<string, mixed>|null>|null
     */
    protected function getItemActionsSchemaFor(string $key): ?array
    {
        $schemas = [];

        foreach (self::ITEM_ACTIONS as $name) {
            if (! isset($this->itemActionModifiers[$name]) || ($action = $this->getItemAction($name)) === null) {
                continue;
            }

            $action->arguments(['item' => $key]);
            $schemas[$name] = $action->isVisible() ? $action->toSchema() : null;
        }

        return $schemas === [] ? null : $schemas;
    }

    /**
     * The visible extra item actions of the item `$key`.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function getExtraItemActionsSchemaFor(string $key): ?array
    {
        if ($this->extraItemActions === [] || $this->isReadOnly()) {
            return null;
        }

        $schemas = [];

        foreach ($this->getExtraItemActions() as $action) {
            $action->arguments(['item' => $key]);

            if ($action->isVisible()) {
                $schemas[] = $action->toSchema();
            }
        }

        return $schemas === [] ? null : $schemas;
    }

    /**
     * The per-item action props of the item `$key` (only the ones it has).
     *
     * @return array<string, mixed>
     */
    protected function getItemActionsProps(string $key): array
    {
        if ($this->isReadOnly()) {
            return [];
        }

        return array_filter([
            'itemActions' => $this->getItemActionsSchemaFor($key),
            'actions' => $this->getExtraItemActionsSchemaFor($key),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Whether the built-in action may be shown: allowed by the field and not hidden.
     */
    protected function isItemActionShown(string $name): bool
    {
        $action = $this->getItemAction($name);

        if ($action === null) {
            return false;
        }

        if (in_array($name, self::ITEM_ACTIONS, true)) {
            $action->arguments(['item' => null]);
        }

        return $action->isVisible();
    }
}
