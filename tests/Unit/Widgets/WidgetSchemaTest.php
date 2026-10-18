<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Widgets\ChartWidget;

it('sends a chart widget component so the renderer can use it instead of the built-in chart', function (): void {
    $widget = new class extends ChartWidget
    {
        protected static ?string $component = 'sales-chart';

        protected function getChartType(): string
        {
            return 'line';
        }

        protected function getData(): array
        {
            return ['datasets' => [['data' => [1, 2]]]];
        }
    };

    $schema = $widget->toSchema();

    expect($schema['type'])->toBe('Chart')
        ->and($schema['component'])->toBe('sales-chart')
        ->and($schema['chartType'])->toBe('line')
        ->and($schema['data'])->toBe(['datasets' => [['data' => [1, 2]]]]);
});
