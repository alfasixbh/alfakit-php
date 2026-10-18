<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsParity;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Pages\Dashboard;
use AlfaSix\Alfakit\Pages\Dashboard\Actions\FilterAction;
use AlfaSix\Alfakit\Pages\Dashboard\Concerns\HasFiltersAction;

class ParityDashboard extends Dashboard
{
    use HasFiltersAction;

    protected static ?string $slug = 'parity-dashboard';

    protected static ?string $routePath = '/parity-dashboard';

    public function getWidgets(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            FilterAction::make()->schema([
                TextInput::make('region')->maxLength(10),
            ]),
        ];
    }
}
