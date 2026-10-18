<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Customizable;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class StatsA extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [Stat::make('A', 1)];
    }
}
