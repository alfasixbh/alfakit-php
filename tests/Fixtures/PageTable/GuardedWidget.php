<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;

class GuardedWidget extends StatsOverviewWidget
{
    protected ?string $secret = null;
}
