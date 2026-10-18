<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

/**
 * A row of KPI cards.
 *
 * ```php
 * protected function getStats(): array
 * {
 *     return [Stat::make('Revenue', '$12,340')->description('+12%')->descriptionIcon('trending-up')->color('success')];
 * }
 * ```
 */
class StatsOverviewWidget extends Widget
{
    /** @var int|string|array<string, int|string|null> */
    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = null;

    protected ?string $description = null;

    protected ?string $pollingInterval = null;

    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = null;

    public function getType(): string
    {
        return 'StatsOverviewWidget';
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        return [];
    }

    protected function getHeading(): ?string
    {
        return $this->heading;
    }

    protected function getDescription(): ?string
    {
        return $this->description;
    }

    protected function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    /**
     * @return int|array<string, int|null>|null
     */
    protected function getColumns(): int|array|null
    {
        return $this->columns;
    }

    protected function getSchemaProps(): array
    {
        $stats = $this->getStats();
        $columns = $this->getColumns();

        return [
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'pollingInterval' => $this->getPollingInterval(),
            'columns' => $columns === null ? null : (is_array($columns) ? $columns : ['default' => 1, 'md' => $columns]),
            'stats' => array_map(static fn (Stat $stat): array => $stat->toSchema(), $stats),
        ];
    }
}
