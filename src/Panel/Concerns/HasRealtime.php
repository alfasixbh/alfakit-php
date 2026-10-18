<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Realtime\RealtimeChannels;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Real-time collaboration over Laravel broadcasting (`->realtime()`): presence on record
 * pages, edit-conflict banners, live tables and widgets and instant database
 * notifications. Off by default; while off no channel, route or observer is registered.
 *
 * Optimistic concurrency on Edit pages (`->detectConflicts()`) works without it.
 */
trait HasRealtime
{
    protected bool|Closure $isRealtime = false;

    protected string|Closure|null $broadcastConnection = null;

    protected bool|Closure $isBroadcastingModelChanges = true;

    protected bool|Closure $isDetectingConflicts = true;

    /**
     * Turn on real-time collaboration. Needs Laravel broadcasting (Reverb, Pusher, Ably)
     * and Laravel Echo in the browser (`php artisan alfakit:realtime` sets both up).
     * A closure is evaluated per request (e.g. per user or tenant); channels, routes and
     * observers are registered unless it is literally `false`.
     */
    public function realtime(bool|Closure $condition = true, string|Closure|null $connection = null): static
    {
        $this->isRealtime = $condition;

        if ($connection !== null) {
            $this->broadcastConnection = $connection;
        }

        return $this;
    }

    /** Whether real-time features are on for the current request. */
    public function hasRealtime(): bool
    {
        return (bool) $this->evaluate($this->isRealtime);
    }

    /**
     * Whether real-time features may be on (anything but `realtime(false)`): decides what is
     * registered when the application boots.
     */
    public function isRealtimeConfigured(): bool
    {
        return $this->isRealtime !== false;
    }

    /** The broadcasting connection (`config/broadcasting.php`) to use; the default when null. */
    public function broadcastConnection(string|Closure|null $connection): static
    {
        $this->broadcastConnection = $connection;

        return $this;
    }

    public function getBroadcastConnection(): ?string
    {
        $connection = $this->evaluate($this->broadcastConnection);

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    /**
     * Broadcast created / updated / deleted events of the panel's resource models (keys
     * only) so live tables, widgets and edit pages follow. On by default with `realtime()`;
     * models outside resources use the `BroadcastsToAlfakit` trait.
     */
    public function broadcastModelChanges(bool|Closure $condition = true): static
    {
        $this->isBroadcastingModelChanges = $condition;

        return $this;
    }

    public function isBroadcastingModelChanges(): bool
    {
        return $this->isRealtimeConfigured() && (bool) $this->evaluate($this->isBroadcastingModelChanges);
    }

    /**
     * Optimistic concurrency on Edit pages: the form sends the version of the record it was
     * loaded with, and saving is refused (with a review / keep-mine prompt) when somebody
     * else saved the record meanwhile. On by default; a page can override it with
     * `protected static ?bool $detectsConflicts`.
     */
    public function detectConflicts(bool|Closure $condition = true): static
    {
        $this->isDetectingConflicts = $condition;

        return $this;
    }

    public function isDetectingConflicts(): bool
    {
        return (bool) $this->evaluate($this->isDetectingConflicts);
    }

    /**
     * The renderers' connection settings: where Echo authorises channels and the public
     * Echo options from `config('alfakit.broadcasting.echo')` (used when the app has no
     * `window.Echo` of its own).
     *
     * @return array<string, mixed>
     */
    protected function getRealtimeConnectionSchema(): array
    {
        $echo = config('alfakit.broadcasting.echo');

        return [
            'live' => true,
            'authEndpoint' => $this->route(($this->hasTenancy() && $this->getTenant() !== null ? 'internal.tenant.' : 'internal.').'realtime.auth'),
            'echo' => is_array($echo) && $echo !== [] ? $echo : null,
        ];
    }

    /**
     * The presence channel of a record page (`presence-` is added by Echo), or null when
     * real-time features are off or the record can't have one.
     *
     * @return array{channel: string, state: string}|null
     */
    public function getRecordPresenceSchema(?Model $record, string $state, ?Authenticatable $user): ?array
    {
        if ($record === null || $user === null || ! $this->hasRealtime()) {
            return null;
        }

        $channel = RealtimeChannels::forRecord($this, $record, $this->getTenant());

        return $channel === null ? null : ['channel' => $channel, 'state' => $state];
    }
}
