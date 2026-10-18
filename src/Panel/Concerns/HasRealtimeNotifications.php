<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Support\Enums\ToastPosition;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use TypeError;

/**
 * Live notifications over Laravel broadcasting (`->realtimeNotifications()`) and where
 * toasts appear (`->toastPosition()`).
 */
trait HasRealtimeNotifications
{
    protected bool|Closure $hasRealtimeNotifications = false;

    protected ToastPosition|string|Closure $toastPosition = ToastPosition::TopEnd;

    /**
     * Subscribe the renderers to the user's private broadcast channel (when Laravel Echo
     * is set up as `window.Echo`): `Notification::broadcast()` toasts arrive instantly and
     * the bell refreshes when `sendToDatabase(..., isEventDispatched: true)` stores one.
     */
    public function realtimeNotifications(bool|Closure $condition = true): static
    {
        $this->hasRealtimeNotifications = $condition;

        return $this;
    }

    public function hasRealtimeNotifications(): bool
    {
        return (bool) $this->evaluate($this->hasRealtimeNotifications);
    }

    public function toastPosition(ToastPosition|string|Closure $position): static
    {
        if (is_string($position) && ToastPosition::tryFrom($position) === null) {
            throw new InvalidArgumentException("Unknown toast position [{$position}]. Use one of: ".implode(', ', array_column(ToastPosition::cases(), 'value')).'.');
        }

        $this->toastPosition = $position;

        return $this;
    }

    public function getToastPosition(): ToastPosition
    {
        $position = $this->evaluate($this->toastPosition);

        return $position instanceof ToastPosition ? $position : (ToastPosition::tryFrom((string) $position) ?? ToastPosition::TopEnd);
    }

    /**
     * The private channel the user's broadcast notifications arrive on (Laravel's
     * `receivesBroadcastNotificationsOn()`, `App.Models.User.1` by default).
     */
    public function getBroadcastChannel(Authenticatable $user): string
    {
        if (method_exists($user, 'receivesBroadcastNotificationsOn')) {
            try {
                $channel = $user->receivesBroadcastNotificationsOn(new \AlfaSix\Alfakit\Notifications\BroadcastNotification([]));
            } catch (TypeError) {
                $channel = null;
            }

            $channel = is_array($channel) ? ($channel[0] ?? null) : $channel;

            if (is_string($channel) && $channel !== '') {
                return $channel;
            }
        }

        return str_replace('\\', '.', $user::class).'.'.$user->getAuthIdentifier();
    }

    /**
     * The user's channel, plus (with `realtime()`) where Echo authorises the panel's
     * channels: `{channel, live?, authEndpoint?, echo?}`.
     *
     * @return array<string, mixed>|null
     */
    protected function getRealtimeNotificationsSchema(?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null;
        }

        // `realtime()` includes live notifications.
        $live = method_exists($this, 'hasRealtime') && $this->hasRealtime();

        if (! $live && ! $this->hasRealtimeNotifications()) {
            return null;
        }

        return array_filter([
            'channel' => $this->getBroadcastChannel($user),
            ...($live ? $this->getRealtimeConnectionSchema() : []),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
