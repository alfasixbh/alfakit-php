<?php

namespace App\Filament\Resources\Orders\Widgets;

use App\Filament\Resources\Orders\Pages\ListOrders;
use Filament\Widgets\Concerns\InteractsWithPageTable;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OrderStats extends BaseWidget
{
    use InteractsWithPageTable;

    protected ?string $pollingInterval = null;

    protected function getTablePage(): string
    {
        return ListOrders::class;
    }

    protected function getStats(): array
    {
        $query = $this->getPageTableQuery();

        return [
            Stat::make('Orders', $query->count())
                ->chart([7, 2, 10, 3, 15, 4, 17])
                ->color('primary'),
            Stat::make('Open orders', (clone $query)->whereIn('status', ['new', 'processing'])->count()),
            Stat::make('Average price', number_format((float) (clone $query)->avg('total_price'), 2)),
        ];
    }
}
