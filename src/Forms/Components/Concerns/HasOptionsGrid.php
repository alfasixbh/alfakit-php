<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use BackedEnum;
use Closure;
use UnitEnum;

/**
 * Options laid out in a grid (`columns()` / `gridColumns()`), filled row by row or, with
 * `gridDirection('column')`, column by column.
 */
trait HasOptionsGrid
{
    protected int|Closure|null $gridColumns = null;

    protected string|UnitEnum|Closure|null $gridDirection = null;

    public function gridColumns(int|Closure|null $columns): static
    {
        $this->gridColumns = $columns;

        return $this;
    }

    /**
     * Lay the options out in a grid (Filament's `columns()`); same as `gridColumns()`.
     * The options grid has one column count from `md` up, so a breakpoint map uses its
     * `lg` value (else `md`, else the largest).
     *
     * @param  array<string, int|null>|int|Closure|null  $columns
     */
    public function columns(array|int|Closure|null $columns = 2): static
    {
        parent::columns($columns);

        return $this->gridColumns(function () use ($columns): ?int {
            $columns = $this->evaluate($columns);

            if (! is_array($columns)) {
                return $columns === null ? null : (int) $columns;
            }

            $columns = array_filter($columns, static fn (mixed $count): bool => is_int($count));

            return $columns['lg'] ?? $columns['md'] ?? ($columns === [] ? null : max($columns));
        });
    }

    /**
     * `row` (default) fills the grid row by row; `column` fills each column top to bottom.
     * Accepts Filament's `GridDirection` enum as well.
     */
    public function gridDirection(string|UnitEnum|Closure|null $direction): static
    {
        $this->gridDirection = $direction;

        return $this;
    }

    public function getGridColumns(): ?int
    {
        $columns = $this->evaluate($this->gridColumns);

        return $columns === null ? null : (int) $columns;
    }

    public function getGridDirection(): ?string
    {
        $direction = $this->evaluate($this->gridDirection);

        if ($direction instanceof UnitEnum) {
            $direction = $direction instanceof BackedEnum ? (string) $direction->value : $direction->name;
        }

        $direction = is_string($direction) ? strtolower($direction) : null;

        return in_array($direction, ['row', 'column'], true) ? $direction : null;
    }
}
