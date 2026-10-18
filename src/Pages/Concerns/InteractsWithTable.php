<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;

trait InteractsWithTable
{
    protected ?Table $cachedTable = null;

    /**
     * Define the page table. Return the table without columns for no table.
     */
    public function table(Table $table): Table
    {
        return $table;
    }

    public function getTable(): ?Table
    {
        if ($this->cachedTable !== null) {
            return $this->cachedTable;
        }

        $table = $this->table(Table::make($this));

        if ($table->getColumns() === [] && $table->getQuery() === null) {
            return null;
        }

        $table->state(TableState::fromRequest(request(), $table->getQueryStringIdentifier()));

        return $this->cachedTable = $table;
    }

    public function hasTable(): bool
    {
        return $this->getTable() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeTable(Table $table, string $component = 'table'): array
    {
        return ['key' => $component, 'component' => $component, ...$table->toSchema()];
    }
}
