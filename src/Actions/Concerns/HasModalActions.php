<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use Closure;

/**
 * Actions inside an action's modal (`extraModalFooterActions()`, `registerModalActions()`)
 * that can open their own modal, stacked on top of the parent's. A nested action is
 * addressed by its path from the root action (`parent>child`): the server resolves the
 * root the usual way and walks down, so the child shares the parent's record, context
 * and visibility (a child of a hidden or unauthorised action can't run either).
 */
trait HasModalActions
{
    /** Separator between action names in a nested action path. */
    public const PATH_SEPARATOR = '>';

    protected ?Action $parentAction = null;

    protected bool|string|Closure $shouldCancelParentActions = false;

    protected bool|string|Closure $shouldCancelParentActionsOnClose = false;

    protected bool|Closure $shouldOverlayParentActions = false;

    /** @var array<string, mixed>|null Form state the browser sent for this action (a parent of the running one). */
    protected ?array $rawData = null;

    /** @var array<string, mixed> Values `fillData()` wrote, sent back to this action's open modal. */
    protected array $filledData = [];

    /** @var array{name: string, arguments: array<string, mixed>}|null */
    protected ?array $replacementAction = null;

    /** @var array<Action>|Closure|null */
    protected array|Closure|null $registeredModalActions = null;

    public function parentAction(?Action $action): static
    {
        $this->parentAction = $action;

        return $this;
    }

    public function getParentAction(): ?Action
    {
        return $this->parentAction;
    }

    public function getRootAction(): Action
    {
        $action = $this;

        while (($parent = $action->getParentAction()) !== null) {
            $action = $parent;
        }

        /** @var Action $action */
        return $action;
    }

    /**
     * The names from the root action down to this one, joined with `>`.
     */
    public function getActionPath(): string
    {
        $parent = $this->getParentAction();

        return $parent === null ? $this->getName() : $parent->getActionPath().self::PATH_SEPARATOR.$this->getName();
    }

    /**
     * Close the parent modals too after this nested action succeeds. Pass a parent's name
     * to close only that parent and the modals opened from it.
     */
    public function cancelParentActions(bool|string|Closure $condition = true): static
    {
        $this->shouldCancelParentActions = $condition;

        return $this;
    }

    public function shouldCancelParentActions(): bool
    {
        return $this->getParentActionsToCancel() !== null;
    }

    /**
     * `true` (every parent), a parent's name, or null when no parent closes.
     */
    public function getParentActionsToCancel(): true|string|null
    {
        return $this->resolveParentCancellation($this->shouldCancelParentActions);
    }

    /**
     * Closing this nested action's modal (cancel, Escape, clicking away, the close button)
     * closes its parent modals as well; pass a parent's name to stop at that parent.
     */
    public function cancelParentActionsOnClose(bool|string|Closure $condition = true): static
    {
        $this->shouldCancelParentActionsOnClose = $condition;

        return $this;
    }

    public function getParentActionsToCancelOnClose(): true|string|null
    {
        return $this->resolveParentCancellation($this->shouldCancelParentActionsOnClose);
    }

    protected function resolveParentCancellation(bool|string|Closure $condition): true|string|null
    {
        if ($this->parentAction === null) {
            return null;
        }

        $value = $this->evaluate($condition);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $value ? true : null;
    }

    /**
     * Open this nested action's modal on top of its parent's, keeping the parent visible
     * underneath. By default (as in Filament) the parent modal is hidden while this one is
     * open, and shown again when it closes.
     */
    public function overlayParentActions(bool|Closure $condition = true): static
    {
        $this->shouldOverlayParentActions = $condition;

        return $this;
    }

    public function shouldOverlayParentActions(): bool
    {
        return (bool) $this->evaluate($this->shouldOverlayParentActions);
    }

    // ---------------------------------------------------------------- Parent data

    /**
     * The actions whose modals are open, outermost first, ending with this one (injected
     * into closures as `$mountedActions`).
     *
     * @return array<int, Action>
     */
    public function getMountedActions(): array
    {
        $parent = $this->getParentAction();

        return $parent === null ? [$this] : [...$parent->getMountedActions(), $this];
    }

    /**
     * The form state the browser sent for this action while one of its nested actions
     * runs. Used by the action endpoint; read it with `getRawData()`.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyRawData(array $data): static
    {
        $this->rawData = $data;
        $this->getForm()?->applyClientState($data);

        return $this;
    }

    /**
     * The current, unvalidated form state of this action: what the user typed into its
     * modal so far (for a parent action of the running one), or its filled state.
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        $form = $this->getForm();

        if ($form === null) {
            return $this->rawData ?? [];
        }

        $state = $form->getRawState();
        unset($state[\AlfaSix\Alfakit\Schemas\Schema::READ_ONLY_STATE_KEY]);

        return $state;
    }

    /**
     * This action's form state validated with its own rules (no dehydration hooks or
     * `mutateFormDataUsing()`). A failure throws a `ValidationException` whose errors the
     * modal of this action shows.
     *
     *
     * @throws \Illuminate\Validation\ValidationException
     *
     * @return array<string, mixed>
     */
    public function getValidatedData(): array
    {
        return $this->getForm()?->validate() ?? [];
    }

    /**
     * Write values into this action's form (e.g. a parent's, from a nested action): only
     * keys of fields in the form are used (dot notation reaches nested fields); the open
     * modal shows them and they are validated with its rules on submit.
     *
     * @param  array<string, mixed>  $data
     */
    public function fillData(array $data): static
    {
        $form = $this->getForm();

        if ($form === null) {
            return $this;
        }

        $this->fillFormPaths($form, $data, '');

        return $this;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function fillFormPaths(\AlfaSix\Alfakit\Schemas\Schema $form, array $data, string $prefix): void
    {
        foreach ($data as $key => $value) {
            $path = $prefix.$key;

            if ($form->getFieldByStatePath($path) !== null) {
                $form->setStateAt($path, $value);
                $this->filledData[$path] = $value;
            } elseif (is_array($value) && ! array_is_list($value)) {
                $this->fillFormPaths($form, $value, $path.'.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilledData(): array
    {
        return $this->filledData;
    }

    /**
     * Replace this action's modal with another action of the same owner (chaining): after
     * this action runs, the named action opens (or runs, when it has no modal) with the
     * given arguments, for the same record.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function replaceMountedAction(string $name, array $arguments = []): void
    {
        $this->replacementAction = ['name' => $name, 'arguments' => $arguments];
    }

    /**
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    public function getReplacementAction(): ?array
    {
        return $this->replacementAction;
    }

    /**
     * Actions that can be triggered from inside this action's modal (e.g. from custom
     * modal content) without being rendered in its footer.
     *
     * @param  array<Action>|Closure|null  $actions
     */
    public function registerModalActions(array|Closure|null $actions): static
    {
        $this->registeredModalActions = $actions;

        return $this;
    }

    /**
     * The extra footer buttons and button groups of this action's modal, configured as its
     * children (every action inside a group too).
     *
     * @return array<Action|ActionGroup>
     */
    public function getExtraModalFooterActions(): array
    {
        return $this->adoptModalActions($this->evaluate($this->extraModalFooterActions) ?? [], withGroups: true);
    }

    /**
     * Every action that lives inside this action's modal, keyed by name.
     *
     * @return array<string, Action>
     */
    public function getModalActions(): array
    {
        $actions = [];

        foreach ([...$this->adoptModalActions($this->evaluate($this->extraModalFooterActions) ?? []), ...$this->adoptModalActions($this->evaluate($this->registeredModalActions) ?? [])] as $action) {
            $actions[$action->getName()] = $action;
        }

        return $actions;
    }

    public function getModalAction(string $name): ?Action
    {
        return $this->getModalActions()[$name] ?? null;
    }

    /**
     * Walk down a path of modal action names (without this action's own name).
     *
     * @param  array<string>  $path
     */
    public function getNestedModalAction(array $path): ?Action
    {
        $action = $this;

        foreach ($path as $name) {
            $action = $action->getModalAction($name);

            if ($action === null) {
                return null;
            }
        }

        return $action;
    }

    /**
     * Makes the actions children of this one. Groups are kept (with their actions adopted)
     * when `$withGroups` is set, and flattened otherwise.
     *
     * @param  array<mixed>  $actions
     * @return ($withGroups is true ? array<Action|ActionGroup> : array<Action>)
     */
    protected function adoptModalActions(array $actions, bool $withGroups = false): array
    {
        $adopted = [];

        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                $children = $this->adoptModalActions($action->getFlatActions());

                if ($withGroups) {
                    $adopted[] = $action;
                } else {
                    array_push($adopted, ...$children);
                }

                continue;
            }

            if (! $action instanceof Action) {
                continue;
            }

            $action->parentAction($this);

            if ($action->getContext() === null) {
                $action->context($this->getContext());
            }

            if ($action->model === null && ($model = $this->getModel()) !== null) {
                $action->model($model);
            }

            if ($action->recordTitleUsing === null) {
                $action->recordTitleUsing = $this->recordTitleUsing;
            }

            $adopted[] = $action;
        }

        return $adopted;
    }
}
