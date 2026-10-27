<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime\Concerns;

use AlfaSix\Alfakit\Realtime\RealtimeChannels;
use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use AlfaSix\Alfakit\Resources\Resource;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * `live()` for tables and widgets: refresh when records of the followed models are
 * created, updated or deleted (broadcast by `realtime()` panels), debounced. Without
 * `realtime()` or Laravel Echo the component polls at `fallbackPollingInterval` instead.
 */
trait HasLiveUpdates
{
    protected bool|Closure $hasLiveUpdates = false;

    protected int|Closure $liveDebounce = 500;

    protected string|Closure|null $liveFallbackPollingInterval = '30s';

    /** @var array<class-string>|Closure|null */
    protected array|Closure|null $liveModels = null;

    /**
     * @return array<class-string<Model>>
     */
    abstract protected function getDefaultLiveModels(): array;

    /**
     * @param  array<class-string>|Closure|null  $models  Models (or resources) to follow; by default the component's own
     */
    protected function configureLiveUpdates(bool|Closure $condition, int|Closure $debounce, string|Closure|null $fallbackPollingInterval, array|Closure|null $models = null): void
    {
        $this->hasLiveUpdates = $condition;
        $this->liveDebounce = $debounce;
        $this->liveFallbackPollingInterval = $fallbackPollingInterval;

        if ($models !== null) {
            $this->liveModels = $models;
        }
    }

    public function hasLiveUpdates(): bool
    {
        return (bool) $this->evaluate($this->hasLiveUpdates);
    }

    /**
     * @return array<class-string<Model>>
     */
    public function getLiveModels(): array
    {
        $models = $this->evaluate($this->liveModels) ?? $this->getDefaultLiveModels();
        $resolved = [];

        foreach ((array) $models as $model) {
            if (! is_string($model)) {
                continue;
            }

            // A resource stands for its model.
            if (is_subclass_of($model, Resource::class)) {
                $model = $model::getModel();
            }

            $model = ltrim($model, '\\');

            if (is_subclass_of($model, Model::class)) {
                $resolved[$model] = $model;
            }
        }

        return array_values($resolved);
    }

    /**
     * `{channels, debounce, fallbackPollingInterval}`: the private channels to listen on
     * (empty without `realtime()`: the renderer polls instead).
     *
     * @return array<string, mixed>|null
     */
    public function getLiveUpdatesSchema(): ?array
    {
        if (! $this->hasLiveUpdates()) {
            return null;
        }

        $panel = alfakit()->getCurrentPanel();
        $channels = [];

        if ($panel !== null && $panel->hasRealtime() && $panel->auth()->user() !== null) {
            foreach ($this->getLiveModels() as $model) {
                // API records change in their API, not through Eloquent: nothing is ever
                // broadcast for them, so their tables keep the fallback polling.
                if (is_a($model, ApiRecord::class, true)) {
                    continue;
                }

                $channel = RealtimeChannels::forModel($panel, $model, $panel->getTenant());

                if ($channel !== null) {
                    $channels[] = $channel;
                }
            }
        }

        $debounce = $this->evaluate($this->liveDebounce);
        $fallback = $this->evaluate($this->liveFallbackPollingInterval);

        return array_filter([
            'channels' => $channels,
            'debounce' => is_numeric($debounce) ? max(0, (int) $debounce) : 500,
            'fallbackPollingInterval' => is_string($fallback) && $fallback !== '' ? $fallback : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
