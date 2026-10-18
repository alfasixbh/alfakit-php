<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class StatusCountWidget extends StatsOverviewWidget
{
    public ?string $status = null;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        return [Stat::make('Status '.$this->status, Post::query()->where('status', $this->status)->count())];
    }
}
