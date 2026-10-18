<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

class DeliveryCalendar extends FullCalendarWidget
{
    /**
     * @param  array{start: string, end: string, timezone: string}  $fetchInfo
     * @return array<int, array<string, mixed>>
     */
    public function fetchEvents(array $fetchInfo): array
    {
        return Order::query()
            ->whereBetween('shipped_at', [$fetchInfo['start'], $fetchInfo['end']])
            ->get()
            ->map(fn (Order $order): array => [
                'id' => $order->id,
                'title' => $order->number,
                'start' => $order->shipped_at,
            ])
            ->all();
    }
}
