<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Dashboard\Concerns;

use AlfaSix\Alfakit\Pages\Dashboard\DashboardFilters;

/**
 * Dashboard filters edited in an action's modal instead of a form above the widgets. Add a
 * `FilterAction` to the header actions; applied values are validated, kept in the session
 * (per user, panel, tenant and dashboard), or in the URL when
 * `persistsFiltersInSession()` returns false (`DashboardFilters`), and read by widgets that use
 * `InteractsWithPageFilters` as `$this->pageFilters`.
 *
 * ```php
 * class Dashboard extends \AlfaSix\Alfakit\Pages\Dashboard
 * {
 *     use HasFiltersAction;
 *
 *     protected function getHeaderActions(): array
 *     {
 *         return [
 *             FilterAction::make()->schema([
 *                 DatePicker::make('startDate'),
 *                 DatePicker::make('endDate'),
 *             ]),
 *         ];
 *     }
 * }
 * ```
 */
trait HasFiltersAction
{
    /**
     * `false` keeps the applied filters in the page URL (`?filters[startDate]=…`) instead of
     * the session (`DashboardFilters`).
     */
    protected static bool $persistsFiltersInSession = true;

    public function persistsFiltersInSession(): bool
    {
        return static::$persistsFiltersInSession;
    }

    /**
     * The query-string key the filters live under when they are not kept in the session.
     */
    public function getFiltersQueryKey(): string
    {
        return 'filters';
    }

    /**
     * The applied filters. Values read from the URL (`persistsFiltersInSession()` false) are
     * user input, so validate or cast them before trusting them.
     *
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->getFiltersStore()->get();
    }

    public function getFiltersSessionKey(): string
    {
        $panel = $this->getPanel();
        $tenant = $panel->getTenant();

        return implode('.', array_filter([
            'alfakit-dashboard-filters',
            $panel->getId(),
            $tenant === null ? null : 'tenant-'.$tenant->getKey(),
            md5(static::class),
        ]));
    }

    protected function getFiltersStore(): DashboardFilters
    {
        return new DashboardFilters($this->persistsFiltersInSession(), $this->getFiltersSessionKey(), $this->getFiltersQueryKey());
    }

    /**
     * Store new filter values (already validated by the filter action's form). Filters kept
     * in the URL return the redirect to the filtered dashboard.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyFilters(array $data): mixed
    {
        return $this->getFiltersStore()->store($data);
    }

    public function resetFilters(): mixed
    {
        return $this->getFiltersStore()->forget();
    }
}
