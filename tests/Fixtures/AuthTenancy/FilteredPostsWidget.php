<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Widgets\Concerns\InteractsWithPageFilters;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;

class FilteredPostsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $status = $this->pageFilters['status'] ?? null;

        return [
            Stat::make('Posts', Post::query()->when($status, fn ($query) => $query->where('status', $status))->count()),
            Stat::make('Filter', (string) ($status ?? 'all')),
        ];
    }
}
