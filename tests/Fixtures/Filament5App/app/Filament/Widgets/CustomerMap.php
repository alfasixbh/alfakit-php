<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Customer;
use Filament\Widgets\Widget;

class CustomerMap extends Widget
{
    protected string $view = 'filament.widgets.customer-map';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    /**
     * @return array<string, int>
     */
    public function getCountries(): array
    {
        return Customer::query()->selectRaw('country, count(*) as total')->groupBy('country')->pluck('total', 'country')->all();
    }
}
