<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Widgets\Concerns\InteractsWithPageTable;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class PostTableStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '10s';

    protected function getTablePage(): string
    {
        return ListPageTablePosts::class;
    }

    protected function getStats(): array
    {
        return [
            Stat::make('Matching', $this->getPageTableQuery()->count()),
            Stat::make('On page', $this->getPageTableRecords()->count()),
            Stat::make('Total', $this->getPageTableRecordsCount()),
            Stat::make('Rating', (int) $this->getPageTableQuery()->sum('rating')),
        ];
    }
}
