<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

/**
 * Broadcast this model's changes to `realtime()` panels, also when no resource of the
 * panel uses it (models shown in custom tables and widgets). Only keys are broadcast.
 * Define `shouldBroadcastToAlfakit(string $event): bool` to skip some changes.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait BroadcastsToAlfakit
{
    public static function bootBroadcastsToAlfakit(): void
    {
        static::created(static fn (self $model) => ModelChanges::broadcast($model, 'created'));
        static::updated(static fn (self $model) => ModelChanges::broadcast($model, 'updated'));
        static::deleted(static fn (self $model) => ModelChanges::broadcast($model, 'deleted'));

        if (method_exists(static::class, 'restored')) {
            static::restored(static fn (self $model) => ModelChanges::broadcast($model, 'restored'));
        }
    }
}
