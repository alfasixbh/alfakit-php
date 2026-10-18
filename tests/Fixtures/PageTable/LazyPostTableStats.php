<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Widgets\Concerns\InteractsWithPageTable;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class LazyPostTableStats extends StatsOverviewWidget
{
    use InteractsWithPageTable;

    protected function getStats(): array
    {
        return [Stat::make('Lazy matching', $this->getPageTableQuery()->count())];
    }
}
