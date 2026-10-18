<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Alignment;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/**
 * Content slots around a field's or entry's label and control (Filament's `aboveLabel()`,
 * `afterContent()`…). A slot takes a string (rendered as escaped `Text`), an `Htmlable`
 * (trusted markup, rendered as an `Html` component), a schema component (`Icon`, `Text`, `Flex`…), an `Action` or
 * `ActionGroup`, an array mixing these, or a closure returning any of them.
 *
 * Actions in a slot run through the field action endpoint like hint actions: they are
 * part of `getFieldActions()`.
 */
trait HasContentSlots
{
    /** @var array<string, mixed> */
    protected array $slotContents = [];

    /** @var array<string, Alignment|string|Closure|null> */
    protected array $slotAlignments = [];

    public function aboveLabel(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('aboveLabel', $content, $alignment);
    }

    public function beforeLabel(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('beforeLabel', $content, $alignment);
    }

    /**
     * Aligned to the end of the label row by default (next to the hint); pass
     * `Alignment::Start` to place it right after the label.
     */
    public function afterLabel(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('afterLabel', $content, $alignment);
    }

    public function belowLabel(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('belowLabel', $content, $alignment);
    }

    public function aboveContent(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('aboveContent', $content, $alignment);
    }

    public function beforeContent(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('beforeContent', $content, $alignment);
    }

    public function afterContent(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('afterContent', $content, $alignment);
    }

    public function belowContent(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('belowContent', $content, $alignment);
    }

    protected function setSlotContent(string $slot, mixed $content, Alignment|string|Closure|null $alignment): static
    {
        $this->slotContents[$slot] = $content;
        $this->slotAlignments[$slot] = $alignment;

        return $this;
    }

    /**
     * Slot names in render order. Fields add the error message slots.
     *
     * @return array<int, string>
     */
    public function getContentSlotNames(): array
    {
        return ['aboveLabel', 'beforeLabel', 'afterLabel', 'belowLabel', 'aboveContent', 'beforeContent', 'afterContent', 'belowContent'];
    }

    /**
     * The slot's content normalised to schema components and actions.
     *
     * @return array<int, Component|Action|ActionGroup>
     */
    public function getSlotItems(string $slot): array
    {
        if (! array_key_exists($slot, $this->slotContents)) {
            return [];
        }

        return $this->normalizeSlotContent($this->slotContents[$slot]);
    }

    public function getSlotAlignment(string $slot): string
    {
        $alignment = $this->evaluate($this->slotAlignments[$slot] ?? null);

        if ($alignment instanceof Alignment) {
            $alignment = $alignment->value;
        }

        if (in_array($alignment, ['start', 'end', 'between'], true)) {
            return $alignment;
        }

        return $slot === 'afterLabel' ? 'end' : 'start';
    }

    /**
     * Every action placed in a slot (groups flattened), bound to this component.
     *
     * @return array<int, Action>
     */
    public function getSlotActions(): array
    {
        $actions = [];

        foreach (array_keys($this->slotContents) as $slot) {
            foreach ($this->getSlotItems($slot) as $item) {
                foreach ($item instanceof ActionGroup ? $item->getFlatActions() : ($item instanceof Action ? [$item] : []) as $action) {
                    $actions[] = $this->prepareFieldAction($action);
                }
            }
        }

        return $actions;
    }

    /**
     * @return array<int, Component|Action|ActionGroup>
     */
    protected function normalizeSlotContent(mixed $content): array
    {
        $content = $content instanceof Closure ? $this->evaluate($content) : $content;

        if ($content === null || $content === '' || $content === []) {
            return [];
        }

        if ($content instanceof Schema) {
            $content = $content->getComponents(withHidden: true);
        }

        if (is_array($content)) {
            $items = [];

            foreach ($content as $item) {
                array_push($items, ...$this->normalizeSlotContent($item));
            }

            return $items;
        }

        return match (true) {
            $content instanceof Action, $content instanceof ActionGroup, $content instanceof Component => [$content],
            $content instanceof Htmlable => [Html::make($content)],
            is_scalar($content), $content instanceof Stringable => [Text::make((string) $content)],
            default => [],
        };
    }

    /**
     * `slots` for the schema: `{ name: { items: [{ component } | { action }], alignment } }`.
     *
     * @return array<string, array<string, mixed>>|null
     */
    protected function getContentSlotsSchema(): ?array
    {
        if ($this->slotContents === []) {
            return null;
        }

        $disabled = method_exists($this, 'isDisabled') && $this->isDisabled();
        $slots = [];

        foreach ($this->getContentSlotNames() as $slot) {
            $items = $this->getSlotItems($slot);

            if ($items === []) {
                continue;
            }

            $components = array_values(array_filter($items, static fn (mixed $item): bool => $item instanceof Component));
            $visible = [];

            if ($components !== []) {
                // A child container keeps the slot's components out of the parent's tree
                // (no state, no validation) while sharing its record and state for closures.
                $container = Schema::make()->parentComponent($this)->components($components);

                foreach ($container->getComponents() as $component) {
                    $visible[spl_object_id($component)] = true;
                }
            }

            $serialized = [];

            foreach ($items as $index => $item) {
                if ($item instanceof Component) {
                    if (! isset($visible[spl_object_id($item)])) {
                        continue;
                    }

                    $item->generatedKey("{$this->getKey()}::{$slot}.{$index}");
                    $serialized[] = ['component' => $item->toSchema()];

                    continue;
                }

                if ($item instanceof ActionGroup) {
                    foreach ($item->getFlatActions() as $action) {
                        $this->prepareFieldAction($action);
                    }

                    if (! $item->isVisible()) {
                        continue;
                    }

                    $schema = $item->toSchema();
                } else {
                    $action = $this->prepareFieldAction($item);

                    if (! $action->isVisible()) {
                        continue;
                    }

                    $schema = $action->toSchema();
                }

                if ($disabled) {
                    $schema['disabled'] = true;
                }

                $serialized[] = ['action' => $schema];
            }

            if ($serialized === []) {
                continue;
            }

            $slots[$slot] = [
                'items' => $serialized,
                'alignment' => $this->getSlotAlignment($slot),
            ];
        }

        return $slots === [] ? null : $slots;
    }
}
