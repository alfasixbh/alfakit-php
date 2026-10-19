<?php

declare(strict_types=1);

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table->defaultPaginationPageOption(25)->striped();
        });

        FilamentView::registerRenderHook(
            PanelsRenderHook::FOOTER,
            fn (): string => view('partials.footer')->render(),
        );
    }
}
