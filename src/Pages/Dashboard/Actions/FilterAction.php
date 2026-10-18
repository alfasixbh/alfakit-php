<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Dashboard\Actions;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Enums\Color;

/**
 * Opens the dashboard filters (`HasFiltersAction`) in a slide-over. Its form is filled
 * with the applied filters; submitting stores the validated values and reloads the
 * widgets. The badge counts the filters in use.
 *
 * ```php
 * FilterAction::make()->schema([DatePicker::make('startDate'), DatePicker::make('endDate')])
 * ```
 */
class FilterAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'filter';
    }

    public static function make(?string $name = null): static
    {
        return parent::make($name ?? static::getDefaultName());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::dashboard.filters.label'));
        $this->icon('filter');
        $this->color(Color::Gray);
        $this->button();
        $this->slideOver();
        $this->modalHeading(__('alfakit::dashboard.filters.label'));
        $this->modalSubmitActionLabel(__('alfakit::dashboard.filters.apply'));
        $this->badge(fn (): ?int => ($count = count(array_filter($this->getAppliedFilters(), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []))) > 0 ? $count : null);
        $this->fillForm(fn (): array => $this->getAppliedFilters());

        $this->extraModalFooterActions(fn (): array => $this->getAppliedFilters() === [] ? [] : [
            Action::make('resetFilters')
                ->label(__('alfakit::dashboard.filters.reset'))
                ->color(Color::Gray)
                ->link()
                ->cancelParentActions()
                ->action(function (): mixed {
                    $page = $this->getContext();

                    // Filters kept in the URL answer with a redirect to the dashboard.
                    return is_object($page) && method_exists($page, 'resetFilters') ? $page->resetFilters() : null;
                }),
        ]);

        $this->action(function (array $data): mixed {
            $page = $this->getContext();

            // Filters kept in the URL answer with a redirect to the dashboard.
            return is_object($page) && method_exists($page, 'applyFilters') ? $page->applyFilters($data) : null;
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAppliedFilters(): array
    {
        $page = $this->getContext();

        return is_object($page) && method_exists($page, 'getFilters') ? (array) $page->getFilters() : [];
    }
}
