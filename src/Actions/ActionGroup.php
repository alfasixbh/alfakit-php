<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\ActionVariant;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * A dropdown of actions.
 */
class ActionGroup
{
    use Configurable;
    use EvaluatesClosures;
    use HasColor;
    use HasIcon;

    /** @var array<Action|ActionGroup> */
    protected array $actions;

    protected string|Closure|null $label = null;

    protected ActionVariant|Closure|null $variant = null;

    protected Size|Closure|null $size = null;

    protected string|Closure|null $tooltip = null;

    protected bool|Closure $isHidden = false;

    protected string|Closure|null $dropdownPlacement = null;

    protected bool|Closure $isDropdown = true;

    protected bool|Closure $isButtonGroup = false;

    protected \AlfaSix\Alfakit\Support\Enums\Width|string|Closure|null $dropdownWidth = null;

    protected int|Closure|null $dropdownOffset = null;

    protected string|Closure|null $maxHeight = null;

    /** @var Model|array<mixed>|Closure|null */
    protected Model|array|Closure|null $record = null;

    protected ?ActionGroup $parentGroup = null;

    /**
     * @param  array<Action|ActionGroup>  $actions
     */
    final public function __construct(array $actions)
    {
        $this->actions = $actions;

        foreach ($actions as $action) {
            $action->parentGroup($this);
        }
    }

    public function parentGroup(?ActionGroup $group): static
    {
        $this->parentGroup = $group;

        return $this;
    }

    /**
     * Whether this group, or a group it sits in, was hidden with `hidden()`. Used to stop
     * the actions inside it from being run, so it ignores whether children are visible.
     */
    /**
     * @param  Model|array<mixed>|null  $record
     */
    public function isHiddenFor(Model|array|null $record): bool
    {
        $record ??= $this->evaluate($this->record);

        if ($this->evaluate($this->isHidden, ['record' => $record], $record instanceof Model ? [Model::class => $record, $record::class => $record] : [])) {
            return true;
        }

        return $this->parentGroup?->isHiddenFor($record) ?? false;
    }

    /**
     * @param  array<Action|ActionGroup>  $actions
     */
    public static function make(array $actions): static
    {
        $static = new static($actions);
        $static->configure();

        return $static;
    }

    protected function setUp(): void
    {
        $this->icon('alfakit::actions.group');
        $this->variant(ActionVariant::IconButton);
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function variant(ActionVariant|Closure|null $variant): static
    {
        $this->variant = $variant;

        return $this;
    }

    public function button(): static
    {
        return $this->variant(ActionVariant::Button);
    }

    public function iconButton(): static
    {
        return $this->variant(ActionVariant::IconButton);
    }

    public function size(Size|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function dropdownPlacement(string|Closure|null $placement): static
    {
        $this->dropdownPlacement = $placement;

        return $this;
    }

    /**
     * `false`: no dropdown. A nested group then renders its actions as a section of the
     * parent dropdown, divided from the other actions; a top-level group renders its
     * actions inline.
     */
    public function dropdown(bool|Closure $condition = true): static
    {
        $this->isDropdown = $condition;

        return $this;
    }

    public function isDropdown(): bool
    {
        return (bool) $this->evaluate($this->isDropdown);
    }

    /**
     * Render the actions as joined buttons (a segmented control) instead of a dropdown.
     */
    public function buttonGroup(bool|Closure $condition = true): static
    {
        $this->isButtonGroup = $condition;

        return $this;
    }

    public function isButtonGroup(): bool
    {
        return (bool) $this->evaluate($this->isButtonGroup);
    }

    /**
     * Width of the dropdown (`Width::ExtraSmall` … `Width::SevenExtraLarge`, or `xs` … `7xl`).
     */
    public function dropdownWidth(\AlfaSix\Alfakit\Support\Enums\Width|string|Closure|null $width): static
    {
        $this->dropdownWidth = $width;

        return $this;
    }

    public function getDropdownWidth(): ?string
    {
        $width = $this->evaluate($this->dropdownWidth);

        return $width instanceof \AlfaSix\Alfakit\Support\Enums\Width ? $width->value : $width;
    }

    /**
     * Distance in pixels between the trigger and the dropdown (default 4).
     */
    public function dropdownOffset(int|Closure|null $offset): static
    {
        $this->dropdownOffset = $offset;

        return $this;
    }

    public function getDropdownOffset(): ?int
    {
        $offset = $this->evaluate($this->dropdownOffset);

        return $offset === null ? null : (int) $offset;
    }

    /**
     * Largest height of the dropdown (a CSS length such as `200px`); it scrolls beyond.
     */
    public function maxHeight(string|Closure|null $height): static
    {
        $this->maxHeight = $height;

        return $this;
    }

    public function getMaxHeight(): ?string
    {
        $height = $this->evaluate($this->maxHeight);

        // A CSS length only: it ends up in a style attribute.
        return is_string($height) && preg_match('/^\d+(\.\d+)?(px|rem|em|vh|%)$/', $height) === 1 ? $height : null;
    }

    /**
     * @param  Model|array<mixed>|Closure|null  $record
     */
    public function record(Model|array|Closure|null $record): static
    {
        $this->record = $record;

        foreach ($this->actions as $action) {
            $action->record($record);
        }

        return $this;
    }

    /**
     * The key of an array row (custom `records()`), passed on to the group's actions.
     */
    public function recordKey(mixed $key): static
    {
        foreach ($this->actions as $action) {
            $action->recordKey($key);
        }

        return $this;
    }

    public function context(mixed $context): static
    {
        foreach ($this->actions as $action) {
            $action->context($context);
        }

        return $this;
    }

    /**
     * @return array<Action|ActionGroup>
     */
    public function getActions(): array
    {
        return $this->actions;
    }

    /**
     * All nested actions, flattened.
     *
     * @return array<Action>
     */
    public function getFlatActions(): array
    {
        $flat = [];

        foreach ($this->actions as $action) {
            if ($action instanceof ActionGroup) {
                array_push($flat, ...$action->getFlatActions());
            } else {
                $flat[] = $action;
            }
        }

        return $flat;
    }

    /**
     * Binds every nested action to the schema component that renders the group (a
     * section's header actions, a callout's actions), so pages resolve them by name.
     */
    public function schemaComponent(?\AlfaSix\Alfakit\Schemas\Components\Component $component): static
    {
        foreach ($this->getFlatActions() as $action) {
            $action->schemaComponent($component);
        }

        return $this;
    }

    /**
     * Gives every nested action the component's field context (`$get` / `$set`).
     */
    public function fieldComponent(?\AlfaSix\Alfakit\Schemas\Components\Component $component): static
    {
        foreach ($this->getFlatActions() as $action) {
            $action->fieldComponent($component);
        }

        return $this;
    }

    public function isHidden(): bool
    {
        if ($this->evaluate($this->isHidden)) {
            return true;
        }

        return $this->getVisibleActions() === [];
    }

    public function isVisible(): bool
    {
        return ! $this->isHidden();
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getVisibleActions(): array
    {
        return array_values(array_filter($this->actions, static fn (Action|ActionGroup $action): bool => $action->isVisible()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return Serializable::compact([
            'type' => 'ActionGroup',
            'label' => $this->evaluate($this->label),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'variant' => $this->evaluate($this->variant)?->value,
            'size' => $this->evaluate($this->size)?->value,
            'tooltip' => $this->evaluate($this->tooltip),
            'dropdownPlacement' => $this->evaluate($this->dropdownPlacement),
            'dropdown' => $this->isDropdown() ? null : false,
            'buttonGroup' => $this->isButtonGroup() ?: null,
            'dropdownWidth' => $this->getDropdownWidth(),
            'dropdownOffset' => $this->getDropdownOffset(),
            'maxHeight' => $this->getMaxHeight(),
            'actions' => array_map(static fn (Action|ActionGroup $action): array => $action->toSchema(), $this->getVisibleActions()),
        ]);
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        return match ($parameterName) {
            'record' => [$this->evaluate($this->record)],
            default => [],
        };
    }
}
