<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A record of a model was created, updated, deleted or restored. Broadcast (after the
 * transaction commits) on the model's private channel and the record's presence channel
 * with keys only: `{event, model, key, by, at}`. Never attributes: clients refetch
 * through the authorised endpoints.
 */
class RecordChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithBroadcasting;
    use InteractsWithSockets;

    /**
     * @param  array<Channel>  $channels
     * @param  array{event: string, model: string, key: int|string, by: int|string|null, at: int}  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly array $payload,
        ?string $connection = null,
    ) {
        if ($connection !== null) {
            $this->broadcastVia($connection);
        }
    }

    /**
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return RealtimeChannels::RECORD_EVENT;
    }

    /**
     * @return array{event: string, model: string, key: int|string, by: int|string|null, at: int}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
