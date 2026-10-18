<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class PostsChart extends ChartWidget
{
    protected static ?string $heading = 'Posts per month';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        return [
            'datasets' => [['label' => 'Posts', 'data' => [3, 5, 8, 2]]],
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
