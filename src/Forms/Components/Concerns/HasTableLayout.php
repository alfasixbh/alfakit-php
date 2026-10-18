<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\Repeater\TableColumn;
use AlfaSix\Alfakit\Infolists\Components\Entry;
use Closure;

/**
 * `->table([TableColumn::make('Name'), …])` on Repeater and RepeatableEntry: items render
 * as table rows, one cell per item component (in schema order), under the columns'
 * headers. Each column names the component it shows, so a hidden component leaves its
 * cell empty instead of shifting the row.
 */
trait HasTableLayout
{
    /** @var array<TableColumn>|Closure|null */
    protected array|Closure|null $tableColumns = null;

    /**
     * @param  array<TableColumn>|Closure|null  $columns
     */
    public function table(array|Closure|null $columns): static
    {
        $this->tableColumns = $columns;

        return $this;
    }

    /**
     * @return array<TableColumn>
     */
    public function getTableColumns(): array
    {
        return array_values(array_filter($this->evaluate($this->tableColumns) ?? [], static fn (mixed $column): bool => $column instanceof TableColumn));
    }

    public function isTable(): bool
    {
        return $this->getTableColumns() !== [];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function getTableColumnsSchema(): ?array
    {
        $columns = $this->getTableColumns();

        if ($columns === []) {
            return null;
        }

        $children = array_values($this->getChildComponents());
        $schemas = [];

        foreach ($columns as $index => $column) {
            $child = $children[$index] ?? null;

            $name = match (true) {
                $child instanceof Field => $child->getStatePath(isAbsolute: false),
                $child instanceof Entry => $child->getName(),
                default => null,
            };

            $schemas[] = $column->toSchema($name, $child instanceof Field && $child->isMarkedAsRequired());
        }

        return $schemas;
    }
}
