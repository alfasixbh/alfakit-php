<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class SalesWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [Stat::make('Sales', 42)];
    }
}
