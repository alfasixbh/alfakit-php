<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Customizable;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class StatsB extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 2;

    protected static ?array $columnSpanOptions = [1, 'full'];

    protected ?string $heading = 'Second stats';

    protected function getStats(): array
    {
        return [Stat::make('B', 2)];
    }
}
