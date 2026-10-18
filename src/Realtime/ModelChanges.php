<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

use AlfaSix\Alfakit\Panel;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Broadcasts model changes to the panels that follow them (`Panel::realtime()`): the
 * models of their resources (observed automatically) and models with the
 * `BroadcastsToAlfakit` trait.
 */
final class ModelChanges
{
    /** @var array<class-string<Model>, true> */
    private static array $observed = [];

    /**
     * Observe a model class (once) so its changes are broadcast.
     *
     * @param  class-string<Model>  $model
     */
    public static function observe(string $model): void
    {
        if (isset(self::$observed[$model]) || in_array(BroadcastsToAlfakit::class, class_uses_recursive($model), true)) {
            return;
        }

        self::$observed[$model] = true;

        $model::observe(BroadcastModelChanges::class);
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function isObserved(string $model): bool
    {
        return isset(self::$observed[$model]);
    }

    /** Forget observed models (tests; the observers themselves stay registered). */
    public static function flush(): void
    {
        self::$observed = [];
    }

    /**
     * Broadcast that `$record` was `created`, `updated`, `deleted` or `restored`.
     */
    public static function broadcast(Model $record, string $event): void
    {
        if (method_exists($record, 'shouldBroadcastToAlfakit') && ! $record->shouldBroadcastToAlfakit($event)) {
            return;
        }

        $key = $record->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $usesTrait = in_array(BroadcastsToAlfakit::class, class_uses_recursive($record), true);

        foreach (alfakit()->getPanels() as $panel) {
            if (! $panel->isBroadcastingModelChanges()) {
                continue;
            }

            if (! $usesTrait && RealtimeChannels::resourceFor($panel, $record::class) === null) {
                continue;
            }

            $channels = self::channelsFor($panel, $record);

            if ($channels === []) {
                continue;
            }

            broadcast(new RecordChanged($channels, [
                'event' => $event,
                'model' => RealtimeChannels::modelSegment($record::class),
                'key' => $key,
                'by' => self::currentUserKey(),
                'at' => (int) floor(microtime(true) * 1000),
            ], $panel->getBroadcastConnection()))->toOthers();
        }
    }

    /**
     * The model channel and the record's presence channel, in each tenant the record
     * belongs to.
     *
     * @return array<Channel>
     */
    public static function channelsFor(Panel $panel, Model $record): array
    {
        $channels = [];

        foreach (self::tenantSegments($panel, $record) as $tenant) {
            $base = "alfakit.{$panel->getId()}.{$tenant}.".RealtimeChannels::modelSegment($record::class);
            $channels[] = new PrivateChannel($base);

            if (($key = RealtimeChannels::keySegment($record->getKey())) !== null) {
                $channels[] = new PresenceChannel("{$base}.{$key}");
            }
        }

        return $channels;
    }

    /**
     * @return array<string>
     */
    private static function tenantSegments(Panel $panel, Model $record): array
    {
        if (! RealtimeChannels::isTenantScoped($panel, $record::class)) {
            return [RealtimeChannels::NO_TENANT];
        }

        $relation = $record->{RealtimeChannels::tenantRelationshipName($panel, $record::class)}();

        if ($relation instanceof BelongsTo) {
            $keys = [$record->getAttribute($relation->getForeignKeyName())];
        } elseif ($relation instanceof Relation) {
            try {
                $keys = $relation->getQuery()->pluck($relation->getRelated()->getQualifiedKeyName())->all();
            } catch (Throwable) {
                $keys = [];
            }
        } else {
            $keys = [];
        }

        return array_values(array_unique(array_filter(array_map(RealtimeChannels::keySegment(...), $keys), static fn (?string $key): bool => $key !== null)));
    }

    private static function currentUserKey(): int|string|null
    {
        try {
            $key = alfakit()->auth()?->getAuthIdentifier();
        } catch (Throwable) {
            return null;
        }

        return is_int($key) || is_string($key) ? $key : null;
    }
}
