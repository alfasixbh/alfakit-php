<?php

namespace App\Filament\Pages;

use App\Enums\OrderStatus;
use App\Models\Order;
use Mokhosh\FilamentKanban\Pages\KanbanBoard;

class OrdersBoard extends KanbanBoard
{
    protected static string $model = Order::class;

    protected static string $statusEnum = OrderStatus::class;

    protected static string $recordTitleAttribute = 'number';
}
