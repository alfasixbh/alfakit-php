<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('status')
                            ->options(['new' => 'New', 'processing' => 'Processing', 'shipped' => 'Shipped']),
                        DatePicker::make('startDate')
                            ->maxDate(fn ($get) => $get('endDate') ?: now()),
                        DatePicker::make('endDate')
                            ->minDate(fn ($get) => $get('startDate') ?: now())
                            ->maxDate(now()),
                    ])
                    ->columns(3),
            ]);
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
