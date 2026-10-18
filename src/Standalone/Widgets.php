<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

/**
 * A grid of widgets outside a panel (stats, charts, table widgets, custom widgets), with
 * lazy loading and polling as on a dashboard.
 *
 * ```php
 * class AccountStats extends \AlfaSix\Alfakit\Standalone\Widgets
 * {
 *     public function widgets(): array
 *     {
 *         return [OrdersStats::class, RevenueChart::make()->columnSpan(2)];
 *     }
 * }
 * ```
 */
abstract class Widgets extends Component
{
    /**
     * Columns of the widget grid (`3`, or `['default' => 1, 'md' => 2, 'xl' => 3]`).
     *
     * @return int|array<string, int|null>
     */
    public function widgetColumns(): int|array
    {
        return 2;
    }
}
