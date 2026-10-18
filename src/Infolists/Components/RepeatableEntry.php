<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\HasTableLayout;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Renders the child schema once per item of a list (a relationship collection or a JSON array).
 */
class RepeatableEntry extends Entry
{
    use HasTableLayout;

    protected bool|Closure $isContained = true;

    protected int|array|Closure|null $grid = null;

    public function contained(bool|Closure $condition = true): static
    {
        $this->isContained = $condition;

        return $this;
    }

    /**
     * @param  int|array<string, int|null>|Closure|null  $columns
     */
    public function grid(int|array|Closure|null $columns = 2): static
    {
        $this->grid = $columns;

        return $this;
    }

    public function toSchema(): array
    {
        $schema = parent::toSchema();
        unset($schema['schema'], $schema['state']);

        return $schema;
    }

    /**
     * Item fields lay out in two columns from `lg` unless `columns()` says otherwise.
     *
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1, 'lg' => 2];
    }

    protected function getSchemaProps(): array
    {
        $state = $this->getState();
        $items = $state instanceof \Illuminate\Support\Collection ? $state->all() : (is_array($state) ? $state : []);
        $grid = $this->evaluate($this->grid);

        return [
            'items' => array_values(array_map(fn (mixed $item): array => $this->buildItemSchema($item)->toSchema(), $items)),
            'contained' => (bool) $this->evaluate($this->isContained),
            'grid' => $grid === null ? null : (is_array($grid) ? $grid : ['default' => 1, 'lg' => $grid]),
            'table' => $this->getTableColumnsSchema(),
        ];
    }

    protected function buildItemSchema(mixed $item): Schema
    {
        $components = array_map(static fn (Component $component): Component => clone $component, $this->getChildComponents());

        $schema = Schema::make()->components($components)->operation($this->getOperation());

        if ($item instanceof Model) {
            $schema->record($item);
        } else {
            $schema->rawState(is_array($item) ? $item : ['value' => $item]);
        }

        return $schema;
    }
}
