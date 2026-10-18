<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Realtime\Concerns\HasLiveUpdates as BaseHasLiveUpdates;
use Closure;

/**
 * `Table::live()` / `liveUpdates()`: the table refreshes in place (keeping selection,
 * scroll, filters and open modals) when records are updated or deleted, and shows a
 * "3 new records" pill instead of jumping when records are created.
 */
trait HasLiveUpdates
{
    use BaseHasLiveUpdates;

    protected bool|Closure $hasNewRecordsIndicator = true;

    /**
     * @param  array<class-string>|Closure|null  $models  Models (or resources) to follow; the table's model by default
     */
    public function live(
        bool|Closure $condition = true,
        int|Closure $debounce = 500,
        bool|Closure $newRecordsIndicator = true,
        string|Closure|null $fallbackPollingInterval = '30s',
        array|Closure|null $models = null,
    ): static {
        $this->configureLiveUpdates($condition, $debounce, $fallbackPollingInterval, $models);
        $this->hasNewRecordsIndicator = $newRecordsIndicator;

        return $this;
    }

    /**
     * Alias of `live()`.
     *
     * @param  array<class-string>|Closure|null  $models
     */
    public function liveUpdates(
        bool|Closure $condition = true,
        int|Closure $debounce = 500,
        bool|Closure $newRecordsIndicator = true,
        string|Closure|null $fallbackPollingInterval = '30s',
        array|Closure|null $models = null,
    ): static {
        return $this->live($condition, $debounce, $newRecordsIndicator, $fallbackPollingInterval, $models);
    }

    protected function getDefaultLiveModels(): array
    {
        $model = $this->getModel();

        return $model === null ? [] : [$model];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getTableLiveSchema(): ?array
    {
        $schema = $this->getLiveUpdatesSchema();

        if ($schema === null) {
            return null;
        }

        return $this->evaluate($this->hasNewRecordsIndicator) ? [...$schema, 'newRecordsIndicator' => true] : $schema;
    }
}
