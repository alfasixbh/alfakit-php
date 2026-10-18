<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\VerticalAlignment;
use Closure;
use Illuminate\Support\Arr;

/**
 * A row of action buttons placed anywhere in a form or infolist.
 *
 * ```php
 * Actions::make([
 *     Action::make('star')->icon('star')->action(fn (Set $set) => $set('rating', 5)),
 *     Action::make('reset')->color('danger')->action(fn (Set $set) => $set('rating', null)),
 * ])->alignment(Alignment::Center)
 * ```
 *
 * The buttons run through the action endpoint in the component's context, like a field's
 * `suffixActions()` (`field:{schema}::{key}`): `$get` / `$set` read and write the form the
 * component sits in, and the refreshed form comes back in the response. That works in page
 * forms and infolists, in standalone forms and inside action modals. Pages also resolve
 * the actions by name (`getAllActions()`), like section header actions.
 */
class Actions extends Component
{
    /** @var array<Action|ActionGroup>|Closure */
    protected array|Closure $actions = [];

    protected Alignment|string|Closure|null $alignment = null;

    protected VerticalAlignment|string|Closure|null $verticalAlignment = null;

    protected bool|Closure $isFullWidth = false;

    /**
     * @param  array<Action|ActionGroup>|Closure  $actions
     */
    final public function __construct(array|Closure $actions = [])
    {
        $this->actions = $actions;
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    /**
     * @param  array<Action|ActionGroup>|Closure  $actions
     */
    public function actions(array|Closure $actions): static
    {
        $this->actions = $actions;

        return $this;
    }

    /**
     * Horizontal alignment of the buttons: `start` (default), `center`, `end`, `between`
     * or `justify`.
     */
    public function alignment(Alignment|string|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(): static
    {
        return $this->alignment(Alignment::Start);
    }

    public function alignCenter(): static
    {
        return $this->alignment(Alignment::Center);
    }

    public function alignEnd(): static
    {
        return $this->alignment(Alignment::End);
    }

    public function alignBetween(): static
    {
        return $this->alignment(Alignment::Between);
    }

    public function alignJustify(): static
    {
        return $this->alignment(Alignment::Justify);
    }

    /**
     * Vertical position of the row in its grid cell, e.g. `end` to line the buttons up with
     * the inputs of the fields beside them.
     */
    public function verticalAlignment(VerticalAlignment|string|Closure|null $alignment): static
    {
        $this->verticalAlignment = $alignment;

        return $this;
    }

    /**
     * Stretch the buttons to share the full width of the component.
     */
    public function fullWidth(bool|Closure $condition = true): static
    {
        $this->isFullWidth = $condition;

        return $this;
    }

    public function getAlignment(): ?string
    {
        $alignment = $this->evaluate($this->alignment);

        return $alignment instanceof Alignment ? $alignment->value : $alignment;
    }

    public function getVerticalAlignment(): ?string
    {
        $alignment = $this->evaluate($this->verticalAlignment);

        return $alignment instanceof VerticalAlignment ? $alignment->value : $alignment;
    }

    public function isFullWidth(): bool
    {
        return (bool) $this->evaluate($this->isFullWidth);
    }

    /**
     * The configured buttons and groups, bound to this component.
     *
     * @return array<Action|ActionGroup>
     */
    public function getActions(): array
    {
        $actions = [];

        foreach (Arr::wrap($this->evaluate($this->actions)) as $action) {
            if (! $action instanceof Action && ! $action instanceof ActionGroup) {
                continue;
            }

            foreach ($action instanceof ActionGroup ? $action->getFlatActions() : [$action] as $flat) {
                $flat->schemaComponent($this)->fieldComponent($this);
            }

            $actions[] = $action;
        }

        return $actions;
    }

    /**
     * Every action (groups flattened), for pages resolving schema-embedded actions by name.
     *
     * @return array<Action>
     */
    public function getAllActions(): array
    {
        $actions = [];

        foreach ($this->getActions() as $action) {
            array_push($actions, ...($action instanceof ActionGroup ? $action->getFlatActions() : [$action]));
        }

        return $actions;
    }

    /**
     * The actions the field action endpoint may run for this component, keyed by name.
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

    protected function getSchemaProps(): array
    {
        $disabled = $this->isDisabled();
        $actions = [];

        foreach ($this->getActions() as $action) {
            if (! $action->isVisible()) {
                continue;
            }

            $schema = $action->toSchema();

            if ($disabled && $action instanceof Action) {
                $schema['disabled'] = true;
            }

            $actions[] = $schema;
        }

        return [
            'actions' => $actions,
            'alignment' => $this->getAlignment(),
            'verticalAlignment' => $this->getVerticalAlignment(),
            'fullWidth' => $this->isFullWidth() ?: null,
        ];
    }
}
