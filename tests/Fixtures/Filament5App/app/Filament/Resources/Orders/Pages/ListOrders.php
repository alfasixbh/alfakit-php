<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Widgets\OrderStats;
use Filament\Actions\CreateAction;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            OrderStats::class,
        ];
    }

    public function getTabs(): array
    {
        return [
            null => Tab::make('All'),
            'new' => Tab::make()->query(fn (Builder $query): Builder => $query->where('status', OrderStatus::New)),
            'processing' => Tab::make()->query(fn (Builder $query): Builder => $query->where('status', OrderStatus::Processing)),
            'shipped' => Tab::make()->query(fn (Builder $query): Builder => $query->where('status', OrderStatus::Shipped)),
            'delivered' => Tab::make()->query(fn (Builder $query): Builder => $query->where('status', OrderStatus::Delivered)),
            'cancelled' => Tab::make()->query(fn (Builder $query): Builder => $query->where('status', OrderStatus::Cancelled)),
        ];
    }
}
