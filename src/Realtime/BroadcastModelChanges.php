<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

use Illuminate\Database\Eloquent\Model;

/**
 * Observer registered for the models of `realtime()` panels' resources.
 */
class BroadcastModelChanges
{
    public function created(Model $model): void
    {
        ModelChanges::broadcast($model, 'created');
    }

    public function updated(Model $model): void
    {
        ModelChanges::broadcast($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        ModelChanges::broadcast($model, 'deleted');
    }

    public function restored(Model $model): void
    {
        ModelChanges::broadcast($model, 'restored');
    }
}
