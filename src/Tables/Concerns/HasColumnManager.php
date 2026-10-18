<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Enums\ColumnManagerLayout;
use AlfaSix\Alfakit\Tables\Enums\ColumnManagerResetActionPosition;
use AlfaSix\Alfakit\Tables\Enums\FiltersResetActionPosition;
use Closure;

/**
 * Options of the column manager and of the toolbar around the records: when column
 * toggles apply, how the manager opens, first / last page links, the loading skeleton
 * and where the grouping settings live.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasColumnManager
{
    protected bool|Closure $isColumnManagerDeferred = false;

    protected ColumnManagerLayout|string|Closure $columnManagerLayout = ColumnManagerLayout::Dropdown;

    protected ColumnManagerResetActionPosition|string|Closure $columnManagerResetActionPosition = ColumnManagerResetActionPosition::Footer;

    /** @var int|array<string, int|null>|Closure|null */
    protected int|array|Closure|null $columnManagerColumns = null;

    protected FiltersResetActionPosition|string|Closure $filtersResetActionPosition = FiltersResetActionPosition::Footer;

    protected bool|Closure $hasExtremePaginationLinks = false;

    protected bool|Closure $hasLoadingSkeleton = false;

    protected bool|Closure $areGroupingSettingsInDropdownOnDesktop = false;

    /**
     * Column toggles wait for an "Apply" button instead of reloading the table on every
     * click. Off by default in AlfaKit (toggles apply at once); Filament defers them.
     */
    public function deferColumnManager(bool|Closure $condition = true): static
    {
        $this->isColumnManagerDeferred = $condition;

        return $this;
    }

    public function isColumnManagerDeferred(): bool
    {
        return (bool) $this->evaluate($this->isColumnManagerDeferred);
    }

    public function columnManagerLayout(ColumnManagerLayout|string|Closure $layout): static
    {
        $this->columnManagerLayout = $layout;

        return $this;
    }

    public function getColumnManagerLayout(): ColumnManagerLayout
    {
        $layout = $this->evaluate($this->columnManagerLayout);

        return $layout instanceof ColumnManagerLayout ? $layout : (ColumnManagerLayout::tryFrom((string) $layout) ?? ColumnManagerLayout::Dropdown);
    }

    public function columnManagerResetActionPosition(ColumnManagerResetActionPosition|string|Closure $position): static
    {
        $this->columnManagerResetActionPosition = $position;

        return $this;
    }

    public function getColumnManagerResetActionPosition(): ColumnManagerResetActionPosition
    {
        $position = $this->evaluate($this->columnManagerResetActionPosition);

        return $position instanceof ColumnManagerResetActionPosition ? $position : (ColumnManagerResetActionPosition::tryFrom((string) $position) ?? ColumnManagerResetActionPosition::Footer);
    }

    /**
     * Lay the column manager's checkboxes out in columns: `2`, or breakpoints
     * (`['default' => 1, 'md' => 2]`).
     *
     * @param  int|array<string, int|null>|Closure|null  $columns
     */
    public function columnManagerColumns(int|array|Closure|null $columns): static
    {
        $this->columnManagerColumns = $columns;

        return $this;
    }

    /**
     * @return array<string, int|null>|null
     */
    public function getColumnManagerColumns(): ?array
    {
        $columns = $this->evaluate($this->columnManagerColumns);

        if ($columns === null) {
            return null;
        }

        return is_array($columns) ? $columns : ['default' => max(1, min(12, (int) $columns))];
    }

    public function filtersResetActionPosition(FiltersResetActionPosition|string|Closure $position): static
    {
        $this->filtersResetActionPosition = $position;

        return $this;
    }

    public function getFiltersResetActionPosition(): FiltersResetActionPosition
    {
        $position = $this->evaluate($this->filtersResetActionPosition);

        return $position instanceof FiltersResetActionPosition ? $position : (FiltersResetActionPosition::tryFrom((string) $position) ?? FiltersResetActionPosition::Footer);
    }

    /**
     * Add "first page" and "last page" buttons to numbered pagination.
     */
    public function extremePaginationLinks(bool|Closure $condition = true): static
    {
        $this->hasExtremePaginationLinks = $condition;

        return $this;
    }

    public function hasExtremePaginationLinks(): bool
    {
        return (bool) $this->evaluate($this->hasExtremePaginationLinks);
    }

    /**
     * Cover the records with skeleton rows while sorting, searching, filtering or paging
     * reloads them. Restyle it through `[data-slot="table-loading-skeleton"]`.
     */
    public function loadingSkeleton(bool|Closure $condition = true): static
    {
        $this->hasLoadingSkeleton = $condition;

        return $this;
    }

    public function hasLoadingSkeleton(): bool
    {
        return (bool) $this->evaluate($this->hasLoadingSkeleton);
    }

    /**
     * Keep the grouping settings in a dropdown on desktop too. By default desktops show
     * them inline in the toolbar and small screens use the dropdown.
     */
    public function groupingSettingsInDropdownOnDesktop(bool|Closure $condition = true): static
    {
        $this->areGroupingSettingsInDropdownOnDesktop = $condition;

        return $this;
    }

    public function areGroupingSettingsInDropdownOnDesktop(): bool
    {
        return (bool) $this->evaluate($this->areGroupingSettingsInDropdownOnDesktop);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeToolbarOptions(): array
    {
        $columnManager = $this->hasToggleableColumns() || $this->hasReorderableColumns();

        return [
            'deferColumnManager' => $columnManager && $this->isColumnManagerDeferred() ? true : null,
            'columnManagerLayout' => $columnManager && $this->getColumnManagerLayout() !== ColumnManagerLayout::Dropdown ? $this->getColumnManagerLayout()->value : null,
            'columnManagerResetActionPosition' => $columnManager && $this->getColumnManagerResetActionPosition() !== ColumnManagerResetActionPosition::Footer ? $this->getColumnManagerResetActionPosition()->value : null,
            'columnManagerColumns' => $columnManager ? $this->getColumnManagerColumns() : null,
            'filtersResetActionPosition' => $this->getFiltersResetActionPosition() !== FiltersResetActionPosition::Footer ? $this->getFiltersResetActionPosition()->value : null,
            'extremePaginationLinks' => $this->hasExtremePaginationLinks() ?: null,
            'loadingSkeleton' => $this->hasLoadingSkeleton() ?: null,
            'groupingSettingsInDropdownOnDesktop' => $this->areGroupingSettingsInDropdownOnDesktop() ?: null,
        ];
    }
}
