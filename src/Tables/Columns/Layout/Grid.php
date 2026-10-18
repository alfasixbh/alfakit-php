<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Layout;

use Closure;

/**
 * Columns arranged in a responsive grid inside a record.
 *
 * ```php
 * Grid::make(['default' => 1, 'md' => 2])->schema([
 *     TextColumn::make('phone'),
 *     TextColumn::make('email'),
 * ])
 * ```
 */
class Grid extends Component
{
    /** @var int|array<string, int|null>|Closure */
    protected int|array|Closure $columns = 2;

    /**
     * @param  int|array<string, int|null>|array<\AlfaSix\Alfakit\Tables\Columns\Column|Component>|Closure  $columns  Column count, or (like the other layouts) the schema.
     */
    public static function make(array|int|Closure $columns = 2): static
    {
        $static = new static([]);

        if (is_array($columns) && array_is_list($columns) && $columns !== [] && is_object($columns[0])) {
            $static->schema($columns);
        } else {
            $static->columns($columns);
        }

        $static->configure();

        return $static;
    }

    /**
     * @param  int|array<string, int|null>|Closure  $columns  A count from `lg` up, or a breakpoint map.
     */
    public function columns(int|array|Closure $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        $columns = $this->evaluate($this->columns);

        return [
            'columns' => is_array($columns) ? $columns : ['default' => 1, 'lg' => $columns],
        ];
    }
}
