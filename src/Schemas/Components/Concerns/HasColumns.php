<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;

/**
 * Responsive grid configuration. Accepts an integer (applies to `lg` and up) or a
 * breakpoint map such as `['default' => 1, 'md' => 2, 'xl' => 4]`.
 */
trait HasColumns
{
    /** @var array<string, int|null>|int|Closure|null */
    protected array|int|Closure|null $columns = null;

    /**
     * @param  array<string, int|null>|int|Closure|null  $columns
     */
    public function columns(array|int|Closure|null $columns = 2): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @return array<string, int|null>
     */
    public function getColumns(): array
    {
        $columns = $this->evaluate($this->columns);

        if ($columns === null) {
            return $this->getDefaultColumns();
        }

        if (! is_array($columns)) {
            return ['default' => 1, 'lg' => (int) $columns];
        }

        return $columns;
    }

    /**
     * @return array<string, int|null>
     */
    protected function getDefaultColumns(): array
    {
        return ['default' => 1];
    }
}
