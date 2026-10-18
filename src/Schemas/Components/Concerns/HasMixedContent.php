<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Html;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Schemas\Schema;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Stringable;

/**
 * A list of actions and schema components placed in a fixed spot of a layout component
 * (a section header's `afterHeader()`, a callout's `controls()`). Actions are bound to the
 * owning component, so pages resolve them by name (`getAllActions()`) and they run
 * through the page's action endpoint like section header actions. Components are
 * rendered as given; they are not part of the form's state.
 */
trait HasMixedContent
{
    /**
     * @return array<int, Component|Action|ActionGroup>
     */
    protected function normalizeMixedContent(mixed $content): array
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
                array_push($items, ...$this->normalizeMixedContent($item));
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
     * Every action in the content (groups flattened), bound to this component.
     *
     * @param  array<int, Component|Action|ActionGroup>  $items
     * @return array<int, Action>
     */
    protected function getMixedContentActions(array $items): array
    {
        $actions = [];

        foreach ($items as $item) {
            foreach ($item instanceof ActionGroup ? $item->getFlatActions() : ($item instanceof Action ? [$item] : []) as $action) {
                $actions[] = $action->schemaComponent($this);
            }
        }

        return $actions;
    }

    /**
     * `[{ component } | { action }]` for the schema; hidden items are left out.
     *
     * @param  array<int, Component|Action|ActionGroup>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function serializeMixedContent(array $items, string $name): array
    {
        $components = array_values(array_filter($items, static fn (mixed $item): bool => $item instanceof Component));
        $visible = [];

        if ($components !== []) {
            // A child container evaluates visibility with this component's record and state
            // while keeping the components out of the parent's tree.
            $container = Schema::make()->parentComponent($this)->components($components);

            foreach ($container->getComponents() as $component) {
                $visible[spl_object_id($component)] = true;
            }
        }

        $serialized = [];

        foreach ($items as $index => $item) {
            if ($item instanceof Component) {
                if (isset($visible[spl_object_id($item)])) {
                    $item->generatedKey("{$this->getKey()}::{$name}.{$index}");
                    $serialized[] = ['component' => $item->toSchema()];
                }

                continue;
            }

            if ($item instanceof ActionGroup) {
                foreach ($item->getFlatActions() as $action) {
                    $action->schemaComponent($this);
                }
            } else {
                $item->schemaComponent($this);
            }

            if ($item->isVisible()) {
                $serialized[] = ['action' => $item->toSchema()];
            }
        }

        return $serialized;
    }
}
