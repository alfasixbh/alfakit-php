<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Dashboard\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Pages\Dashboard\DashboardFilters;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;

/**
 * Filters shared by every widget of a dashboard. Define the fields in `filtersForm()`;
 * applied values are validated, kept in the session (per user, panel, tenant and dashboard)
 * and read by widgets that use `InteractsWithPageFilters` as `$this->pageFilters`. With
 * `$persistsFiltersInSession = false` they live in the URL instead (`DashboardFilters`), and
 * `live()` fields wait for "Apply" too.
 *
 * Fields marked `live()` apply as soon as they change (the live refresh stores the filters
 * and the renderer reloads the widgets); the "Apply" button stays for the other fields and
 * disappears when every field is live.
 *
 * ```php
 * class Dashboard extends \AlfaSix\Alfakit\Pages\Dashboard
 * {
 *     use HasFiltersForm;
 *
 *     public function filtersForm(Schema $schema): Schema
 *     {
 *         return $schema->components([DatePicker::make('startDate'), DatePicker::make('endDate')])->columns(2);
 *     }
 * }
 * ```
 */
trait HasFiltersForm
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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema;
    }

    public function form(Schema $schema): Schema
    {
        $schema = $this->filtersForm($schema);

        foreach ($schema->getFlatFields(withHidden: true) as $field) {
            if ($field->isLive()) {
                $field->afterStateUpdated(fn () => $this->applyLiveFilters());
            }
        }

        return $schema;
    }

    /**
     * Whether any filter field is `live()` (applied on change, without the "Apply" button).
     */
    public function hasLiveFilters(): bool
    {
        foreach ($this->getForm()?->getFlatFields(withHidden: true) ?? [] as $field) {
            if ($field->isLive()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every filter field is `live()`, so the "Apply" button has nothing left to do.
     */
    public function hasOnlyLiveFilters(): bool
    {
        // Filters kept in the URL change it, which only "Apply" does.
        if (! $this->persistsFiltersInSession()) {
            return false;
        }

        $fields = $this->getForm()?->getFlatFields(withHidden: true) ?? [];

        if ($fields === []) {
            return false;
        }

        foreach ($fields as $field) {
            if (! $field->isLive()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Store the filters during a live refresh when they validate; invalid values are left
     * for the form to show and the widgets keep the last applied filters.
     */
    protected function applyLiveFilters(): void
    {
        $form = $this->getForm();

        if ($form === null || ! $this->persistsFiltersInSession() || $form->getValidator()->fails()) {
            return;
        }

        $this->getFiltersStore()->store($form->getState());
    }

    /**
     * Validate and store new filter values.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyFilters(array $data): mixed
    {
        return $this->getFiltersStore()->store($data) ?? back();
    }

    public function resetFilters(): mixed
    {
        return $this->getFiltersStore()->forget() ?? back();
    }

    protected function fillForm(): void
    {
        $this->formData = $this->getFilters();

        parent::fillForm();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('applyFilters')
                ->label(__('alfakit::dashboard.filters.apply'))
                ->icon('filter')
                ->color(Color::Primary)
                ->button()
                ->visible(fn (): bool => ! $this->hasOnlyLiveFilters())
                ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
                ->action(fn (array $data) => $this->applyFilters($data)),
            Action::make('resetFilters')
                ->label(__('alfakit::dashboard.filters.reset'))
                ->color(Color::Gray)
                ->button()
                ->ghost()
                ->visible(fn (): bool => $this->getFilters() !== [])
                ->action(fn () => $this->resetFilters()),
        ];
    }

    protected function getContentBlocks(): array
    {
        $form = $this->getForm();

        return [
            ...($form === null ? [] : [[...$this->serializeForm($form), ...($this->hasLiveFilters() && $this->persistsFiltersInSession() ? ['liveFilters' => true] : [])]]),
            ...parent::getContentBlocks(),
        ];
    }
}
