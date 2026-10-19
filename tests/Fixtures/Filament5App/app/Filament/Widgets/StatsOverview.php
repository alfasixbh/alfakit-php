<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Customer;
use App\Models\Order;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $status = $this->pageFilters['status'] ?? null;

        $orders = Order::query()->when($status, fn ($query) => $query->where('status', $status));

        return [
            Stat::make('Revenue', '$'.number_format((float) $orders->sum('total_price'), 2))
                ->description('32k increase')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('success'),
            Stat::make('New customers', Customer::query()->count())
                ->description('3% decrease')
                ->descriptionIcon(Heroicon::ArrowTrendingDown)
                ->color('danger'),
            Stat::make('New orders', $orders->count())
                ->description('7% increase')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
        ];
    }
}
