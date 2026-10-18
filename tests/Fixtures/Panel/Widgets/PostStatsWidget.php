<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Panel\Widgets;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class PostStatsWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Posts', Post::query()->count())->icon('file-text')->color('primary'),
        ];
    }
}
