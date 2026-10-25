<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityChartWidget;
use Illuminate\Http\Request;

beforeEach(function (): void {
    ParityChartWidget::$seenFilters = null;
    ParityChartWidget::$empty = false;
});

function chartSchemaFor(string $query = ''): array
{
    app()->instance('request', Request::create('/admin?'.$query));

    return ParityChartWidget::make()->toSchema();
}

it('serialises the filters form with its defaults, colour and collapsible flag', function (): void {
    $schema = chartSchemaFor();

    expect($schema['color'])->toBe('info')
        ->and($schema['collapsible'])->toBeTrue()
        ->and($schema['filtersForm']['deferred'])->toBeTrue()
        ->and($schema['filtersForm']['queryKey'])->toBe('chartFilters')
        ->and($schema['filtersForm']['state'])->toMatchArray(['range' => 'week', 'limit' => 5])
        ->and(array_column($schema['filtersForm']['components'], 'name'))->toBe(['range', 'limit'])
        ->and($schema)->not->toHaveKey('empty')
        ->and(ParityChartWidget::$seenFilters)->toMatchArray(['range' => 'week']);
});

it('reads the filters of this widget from the query string', function (): void {
    $id = ParityChartWidget::make()->getId();

    $schema = chartSchemaFor(http_build_query(['chartFilters' => [$id => ['range' => 'month', 'limit' => '9', 'unknown' => 'x']]]));

    expect($schema['filtersForm']['state'])->toMatchArray(['range' => 'month', 'limit' => '9'])
        ->and($schema['filtersForm']['state'])->not->toHaveKey('unknown')
        ->and(ParityChartWidget::$seenFilters['range'])->toBe('month');
});

it('falls back to the defaults when the query string fails validation', function (): void {
    $id = ParityChartWidget::make()->getId();

    chartSchemaFor(http_build_query(['chartFilters' => [$id => ['range' => 'forever']]]));

    expect(ParityChartWidget::$seenFilters['range'])->toBe('week');

    // Another widget's values are ignored.
    chartSchemaFor(http_build_query(['chartFilters' => ['other-widget' => ['range' => 'month']]]));

    expect(ParityChartWidget::$seenFilters['range'])->toBe('week');
});

it('shows the empty state when getData() returns nothing', function (): void {
    ParityChartWidget::$empty = true;

    $schema = chartSchemaFor();

    expect($schema['empty'])->toBeTrue()
        ->and($schema['emptyState'])->toBe(['heading' => 'No orders yet', 'icon' => 'chart-bar'])
        ->and($schema['data'])->toBe([]);
});

it('reads its filters from the host page query string on internal endpoints', function (): void {
    $id = ParityChartWidget::make()->getId();

    // A starter kit dashboard or standalone page loads widgets from `/_alfakit/standalone/components`,
    // which receives the page's own query string as `tableQuery`.
    app()->instance('request', Request::create('/admin/_alfakit/standalone/components', 'GET', [
        'tableQuery' => '?'.http_build_query(['chartFilters' => [$id => ['range' => 'month']], 'widgetFilters' => [$id => 'x']]),
    ]));

    $schema = ParityChartWidget::make()->toSchema();

    expect($schema['filtersForm']['state'])->toMatchArray(['range' => 'month'])
        ->and(ParityChartWidget::$seenFilters['range'])->toBe('month');
});
