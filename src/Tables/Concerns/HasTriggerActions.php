<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Support\Enums\SlideOverPosition;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;

/**
 * The table's own toolbar buttons (filters, column manager, grouping, reordering) and the
 * buttons inside the filters form can be restyled with a closure receiving a prepared
 * `Action`, as in Filament:
 *
 * ```php
 * $table->filtersTriggerAction(fn (Action $action) => $action->button()->label('Filter'))
 *     ->reorderRecordsTriggerAction(fn (Action $action, bool $isReordering) => $action
 *         ->label($isReordering ? 'Done' : 'Reorder'))
 * ```
 *
 * The action is only a button description: label, icon, colour, variant (`button()`,
 * `link()`, `iconButton()`, `outlined()`), size, badge, tooltip, extra attributes and
 * visibility are used; the table still decides what clicking it does.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait HasTriggerActions
{
    protected ?Closure $filtersTriggerAction = null;

    protected ?Closure $filtersApplyAction = null;

    protected ?Closure $filtersResetAction = null;

    protected ?Closure $filtersRemoveAllAction = null;

    protected ?Closure $columnManagerTriggerAction = null;

    protected ?Closure $columnManagerApplyAction = null;

    protected ?Closure $columnManagerResetAction = null;

    protected ?Closure $groupRecordsTriggerAction = null;

    protected ?Closure $reorderRecordsTriggerAction = null;

    /**
     * The button opening the filters dropdown or modal.
     */
    public function filtersTriggerAction(?Closure $callback): static
    {
        $this->filtersTriggerAction = $callback;

        return $this;
    }

    /**
     * The "Apply" button of deferred filters.
     */
    public function filtersApplyAction(?Closure $callback): static
    {
        $this->filtersApplyAction = $callback;

        return $this;
    }

    /**
     * The button resetting the filters form.
     */
    public function filtersResetAction(?Closure $callback): static
    {
        $this->filtersResetAction = $callback;

        return $this;
    }

    /**
     * The button removing every active filter, next to the filter indicators.
     */
    public function filtersRemoveAllAction(?Closure $callback): static
    {
        $this->filtersRemoveAllAction = $callback;

        return $this;
    }

    /**
     * The button opening the column manager.
     */
    public function columnManagerTriggerAction(?Closure $callback): static
    {
        $this->columnManagerTriggerAction = $callback;

        return $this;
    }

    /**
     * The "Apply" button of a deferred column manager (`deferColumnManager()`).
     */
    public function columnManagerApplyAction(?Closure $callback): static
    {
        $this->columnManagerApplyAction = $callback;

        return $this;
    }

    /**
     * The column manager's reset button.
     */
    public function columnManagerResetAction(?Closure $callback): static
    {
        $this->columnManagerResetAction = $callback;

        return $this;
    }

    /**
     * The button opening the "group by" settings.
     */
    public function groupRecordsTriggerAction(?Closure $callback): static
    {
        $this->groupRecordsTriggerAction = $callback;

        return $this;
    }

    /**
     * The button toggling reorder mode; the closure also receives `bool $isReordering`
     * and runs once for each state.
     */
    public function reorderRecordsTriggerAction(?Closure $callback): static
    {
        $this->reorderRecordsTriggerAction = $callback;

        return $this;
    }

    /**
     * The customised buttons, keyed by role. Buttons that were not customised are left
     * out and keep the renderer's default look.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function serializeTriggerActions(): array
    {
        $triggers = [
            'filters' => [$this->filtersTriggerAction, 'filters', __('alfakit::tables.filters.label'), []],
            'filtersApply' => [$this->filtersApplyAction, 'applyFilters', __('alfakit::tables.filters.apply'), []],
            'filtersReset' => [$this->filtersResetAction, 'resetFilters', __('alfakit::tables.filters.reset'), []],
            'filtersRemoveAll' => [$this->filtersRemoveAllAction, 'removeAllFilters', __('alfakit::tables.filters.remove_all'), []],
            'columnManager' => [$this->columnManagerTriggerAction, 'columnManager', __('alfakit::tables.columns.toggle'), []],
            'columnManagerApply' => [$this->columnManagerApplyAction, 'applyColumnManager', __('alfakit::tables.filters.apply'), []],
            'columnManagerReset' => [$this->columnManagerResetAction, 'resetColumnManager', __('alfakit::tables.columns.reset'), []],
            'groupRecords' => [$this->groupRecordsTriggerAction, 'groupRecords', __('alfakit::tables.grouping.trigger'), []],
            'reorderRecords' => [$this->reorderRecordsTriggerAction, 'reorderRecords', __('alfakit::tables.reorder.start'), ['isReordering' => false]],
            'reorderRecordsActive' => [$this->reorderRecordsTriggerAction, 'reorderRecords', __('alfakit::tables.reorder.stop'), ['isReordering' => true]],
        ];

        $schema = [];

        foreach ($triggers as $key => [$callback, $name, $label, $injections]) {
            if ($callback !== null) {
                $schema[$key] = $this->makeTriggerActionSchema($callback, $name, $label, $injections, withModal: $key === 'columnManager');
            }
        }

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $injections
     * @return array<string, mixed>
     */
    protected function makeTriggerActionSchema(Closure $callback, string $name, string $label, array $injections, bool $withModal = false): array
    {
        $action = Action::make($name)->label($label)->color('gray');
        $result = $this->evaluate($callback, ['action' => $action, ...$injections], [Action::class => $action]);
        $action = $result instanceof Action ? $result : $action;

        if (! $action->isVisible()) {
            return ['hidden' => true];
        }

        $schema = $action->toSchema();

        // Only the look is sent: the table decides what the button does.
        return Serializable::compact([
            'label' => $schema['label'] ?? null,
            'icon' => $schema['icon'] ?? null,
            'iconPosition' => $schema['iconPosition'] ?? null,
            'color' => $schema['color'] ?? null,
            'variant' => $schema['variant'] ?? null,
            'outlined' => $schema['outlined'] ?? null,
            'size' => $schema['size'] ?? null,
            'badge' => $schema['badge'] ?? null,
            'badgeColor' => $schema['badgeColor'] ?? null,
            'tooltip' => $schema['tooltip'] ?? null,
            'disabled' => $schema['disabled'] ?? null,
            'extraAttributes' => $schema['extraAttributes'] ?? null,
            'modal' => $withModal ? ($this->makeTriggerActionModalSchema($action) ?: null) : null,
        ]);
    }

    /**
     * The modal options set on the trigger (`slideOver()`, `modalWidth()`, `modalHeading()`,
     * `modalDescription()`), for a panel the table opens itself (the column manager with
     * `ColumnManagerLayout::Modal`). Only options set explicitly are sent.
     *
     * @return array<string, mixed>
     */
    protected function makeTriggerActionModalSchema(Action $action): array
    {
        /** @var array{heading: mixed, description: mixed, width: mixed, slideOver: mixed} $explicit */
        $explicit = (fn (): array => [
            'heading' => $this->modalHeading,
            'description' => $this->modalDescription,
            'width' => $this->modalWidth,
            'slideOver' => $this->isModalSlideOver,
        ])->call($action);

        $slideOver = (bool) $action->evaluate($explicit['slideOver']);

        return Serializable::compact([
            'heading' => $explicit['heading'] !== null ? $action->getModalHeading() : null,
            'description' => $explicit['description'] !== null ? $action->getModalDescription() : null,
            'width' => $explicit['width'] !== null || $slideOver ? $action->getModalWidth() : null,
            'slideOver' => $slideOver ?: null,
            'slideOverPosition' => $slideOver && $action->getSlideOverPosition() === SlideOverPosition::Start ? 'start' : null,
        ]);
    }
}
