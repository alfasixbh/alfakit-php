<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Product;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ProductCatalog extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        return $table
            ->query(Product::query()->where('is_visible', true))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('price')->money('usd')->sortable(),
            ]);
    }

    public function render(): View
    {
        return view('livewire.product-catalog');
    }
}
