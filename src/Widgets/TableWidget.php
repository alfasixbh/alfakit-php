<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets;

use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;

/**
 * A widget wrapping a Table. The table's query string keys are prefixed with the widget id.
 */
abstract class TableWidget extends Widget
{
    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = null;

    protected ?Table $cachedTable = null;

    abstract public function table(Table $table): Table;

    public function getTable(): Table
    {
        if ($this->cachedTable !== null) {
            return $this->cachedTable;
        }

        $table = $this->table(Table::make($this->page ?? $this)->queryStringIdentifier($this->getId()));

        if ($this->heading !== null) {
            $table->heading($this->heading);
        }

        // `live()` on the widget: its table follows the changes.
        if ($this->hasLiveUpdates()) {
            $table->live(debounce: $this->liveDebounce, fallbackPollingInterval: $this->liveFallbackPollingInterval, models: $this->liveModels);
        }

        $table->state(TableState::fromRequest(request(), $table->getQueryStringIdentifier()));

        return $this->cachedTable = $table;
    }

    public function getType(): string
    {
        return 'TableWidget';
    }

    protected function getSchemaProps(): array
    {
        return [
            'component' => 'widget:'.$this->getId(),
            'table' => $this->getTable()->toSchema(),
            // The table follows the changes itself (`Table::live()`).
            'live' => null,
        ];
    }
}
