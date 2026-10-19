<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All products'),
            'visible' => Tab::make()
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('is_visible', true)),
            'low_stock' => Tab::make('Low stock')
                ->badge(fn (): int => ProductResource::getModel()::query()->where('qty', '<', 10)->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('qty', '<', 10)),
        ];
    }
}
