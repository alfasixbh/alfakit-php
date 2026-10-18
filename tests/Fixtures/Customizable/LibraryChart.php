<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Customizable;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class LibraryChart extends StatsOverviewWidget
{
    protected static ?string $libraryLabel = 'Library widget';

    protected function getStats(): array
    {
        return [Stat::make('Library', 3)];
    }
}
