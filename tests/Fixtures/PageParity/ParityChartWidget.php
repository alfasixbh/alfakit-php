<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity;

use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Widgets\ChartWidget;
use AlfaSix\Alfakit\Widgets\ChartWidget\Concerns\HasFiltersSchema;

class ParityChartWidget extends ChartWidget
{
    use HasFiltersSchema;

    protected ?string $heading = 'Orders';

    protected ?string $color = 'info';

    protected bool $isCollapsible = true;

    protected bool $hasDeferredFilters = true;

    protected ?string $emptyStateHeading = 'No orders yet';

    protected ?string $emptyStateIcon = 'chart-bar';

    /** @var array<string, mixed>|null The filters `getData()` saw */
    public static ?array $seenFilters = null;

    public static bool $empty = false;

    public function filtersSchema(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('range')->options(['week' => 'Week', 'month' => 'Month'])->default('week')->required(),
            TextInput::make('limit')->numeric()->default(5),
        ]);
    }

    protected function getChartType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        static::$seenFilters = $this->filters;

        return static::$empty ? [] : ['datasets' => [['label' => 'Orders', 'data' => [1, 2]]], 'labels' => ['A', 'B']];
    }
}
