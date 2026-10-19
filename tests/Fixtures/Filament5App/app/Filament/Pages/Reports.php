<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Order;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.reports';

    protected Width|string|null $maxContentWidth = Width::Full;

    public string $period = 'month';

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getSubheading(): ?string
    {
        return 'Revenue and orders for the selected period.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->schema([
                    DatePicker::make('from')->required(),
                    DatePicker::make('until')->required(),
                ])
                ->action(function (array $data): void {
                    Order::query()->whereBetween('created_at', [$data['from'], $data['until']])->count();
                }),
            Action::make('refresh')
                ->icon('heroicon-m-arrow-path')
                ->action(fn () => $this->dispatch('refresh-reports')),
        ];
    }
}
