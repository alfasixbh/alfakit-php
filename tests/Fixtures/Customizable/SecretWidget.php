<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Customizable;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class SecretWidget extends StatsOverviewWidget
{
    public static bool $visible = false;

    public static function canView(): bool
    {
        return static::$visible;
    }

    protected function getStats(): array
    {
        return [Stat::make('Secret', 4)];
    }
}
