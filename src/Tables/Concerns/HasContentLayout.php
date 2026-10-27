<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Columns\Column;
use AlfaSix\Alfakit\Tables\Columns\ColumnGroup;
use AlfaSix\Alfakit\Tables\Columns\Layout\Component as LayoutComponent;
use Closure;
use InvalidArgumentException;

/**
 * Column layouts (`Split`, `Stack`, `Panel`, `Grid`), `contentGrid()` card layouts and
 * `ColumnGroup` headers. The table's `columns()` may mix plain columns, groups and
 * layouts; `getColumns()` flattens them, the layout tree is serialised separately.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasContentLayout
{
    /** @var int|array<string, int|null>|Closure|null */
    protected int|array|Closure|null $contentGrid = null;

    /** @var array<mixed>|null The evaluated `columns()` items, before flattening. */
    protected ?array $resolvedColumnComponents = null;

    /**
     * Show records as cards in a responsive grid: `contentGrid(['md' => 2, 'xl' => 3])`.
     * An integer is the column count from `lg` up. The breakpoints follow the table's own
     * width (a CSS container query), and a count only applies once each card can be 15rem
     * wide; container keys (`'@lg' => 2`) are used as given.
     *
     * @param  int|array<string, int|null>|Closure|null  $grid
     */
    public function contentGrid(int|array|Closure|null $grid): static
    {
        $this->contentGrid = $grid;

        return $this;
    }

    /**
     * @return array<string, int|null>|null
     */
    public function getContentGrid(): ?array
    {
        $grid = $this->evaluate($this->contentGrid);

        if ($grid === null) {
            return null;
        }

        return is_array($grid) ? $grid : ['default' => 1, 'lg' => (int) $grid];
    }

    /**
     * Whether records render as layout blocks instead of a row of cells.
     */
    public function hasContentLayout(): bool
    {
        if ($this->getContentGrid() !== null) {
            return true;
        }

        foreach ($this->getColumnComponents() as $component) {
            if ($component instanceof LayoutComponent) {
                return true;
            }
        }

        return false;
    }

    /**
     * The items passed to `columns()`: columns, column groups and layouts.
     *
     * @return array<mixed>
     */
    public function getColumnComponents(): array
    {
        $this->getColumns();

        return $this->resolvedColumnComponents ?? [];
    }

    /**
     * @param  array<mixed>  $components
     * @return array<Column>
     */
    protected function flattenColumnComponents(array $components): array
    {
        $columns = [];

        foreach ($components as $component) {
            if ($component instanceof LayoutComponent || $component instanceof ColumnGroup) {
                $component->table($this);
                array_push($columns, ...$component->getColumns());
            } elseif ($component instanceof Column) {
                $columns[] = $component;
            } elseif ($component !== null) {
                // A form layout (`Schemas\Components\Grid`, `Group`, …) imported by mistake used to be dropped,
                // leaving a table without columns and without an error.
                $type = get_debug_type($component);
                $hint = is_object($component) && class_exists('AlfaSix\\Alfakit\\Tables\\Columns\\Layout\\'.class_basename($component))
                    ? ' Use AlfaSix\\Alfakit\\Tables\\Columns\\Layout\\'.class_basename($component).' for a table layout.'
                    : '';

                throw new InvalidArgumentException("Table columns() accepts columns, column groups and table layouts only; got [{$type}].{$hint}");
            }
        }

        return $columns;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function getContentLayoutSchema(): ?array
    {
        if (! $this->hasContentLayout()) {
            return null;
        }

        $nodes = [];

        foreach ($this->getColumnComponents() as $component) {
            $items = match (true) {
                $component instanceof LayoutComponent => [$component->toSchema()],
                $component instanceof ColumnGroup => array_map(LayoutComponent::columnReference(...), $component->getColumns()),
                $component instanceof Column => [LayoutComponent::columnReference($component)],
                default => [],
            };

            foreach ($items as $item) {
                if ($item !== null) {
                    $nodes[] = $item;
                }
            }
        }

        return $nodes;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function getColumnGroupsSchema(): ?array
    {
        $groups = [];

        foreach ($this->getColumnComponents() as $component) {
            if ($component instanceof ColumnGroup && ($schema = $component->toSchema()) !== null) {
                $groups[] = $schema;
            }
        }

        return $groups === [] ? null : $groups;
    }
}
