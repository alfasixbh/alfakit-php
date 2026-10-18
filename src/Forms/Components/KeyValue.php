<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Forms\Components\Concerns\CanCustomizeItemActions;
use AlfaSix\Alfakit\Forms\Components\Repeater\ItemAction;
use Closure;

/**
 * Its add, delete and reorder buttons are actions: customise them with `addAction()`,
 * `deleteAction()` and `reorderAction()` (`fn (Action $action) => $action->…`). Rows are
 * keyed by position: `$arguments['item']` is the row's index, `$arguments['items']` the
 * new order of a reorder.
 */
class KeyValue extends Field
{
    use CanCustomizeItemActions;

    protected string|Closure|null $keyLabel = null;

    protected string|Closure|null $valueLabel = null;

    protected string|Closure|null $keyPlaceholder = null;

    protected string|Closure|null $valuePlaceholder = null;

    protected string|Closure|null $addActionLabel = null;

    protected bool|Closure $isAddable = true;

    protected bool|Closure $isDeletable = true;

    protected bool|Closure $isReorderable = false;

    protected bool|Closure $canEditKeys = true;

    protected bool|Closure $canEditValues = true;

    public function keyLabel(string|Closure|null $label): static
    {
        $this->keyLabel = $label;

        return $this;
    }

    public function valueLabel(string|Closure|null $label): static
    {
        $this->valueLabel = $label;

        return $this;
    }

    public function keyPlaceholder(string|Closure|null $placeholder): static
    {
        $this->keyPlaceholder = $placeholder;

        return $this;
    }

    public function valuePlaceholder(string|Closure|null $placeholder): static
    {
        $this->valuePlaceholder = $placeholder;

        return $this;
    }

    public function addActionLabel(string|Closure|null $label): static
    {
        $this->addActionLabel = $label;

        return $this;
    }

    public function addable(bool|Closure $condition = true): static
    {
        $this->isAddable = $condition;

        return $this;
    }

    public function deletable(bool|Closure $condition = true): static
    {
        $this->isDeletable = $condition;

        return $this;
    }

    public function reorderable(bool|Closure $condition = true): static
    {
        $this->isReorderable = $condition;

        return $this;
    }

    public function editableKeys(bool|Closure $condition = true): static
    {
        $this->canEditKeys = $condition;

        return $this;
    }

    public function editableValues(bool|Closure $condition = true): static
    {
        $this->canEditValues = $condition;

        return $this;
    }

    public function addAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('add', $callback);
    }

    public function deleteAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('delete', $callback);
    }

    public function reorderAction(?Closure $callback): static
    {
        return $this->modifyItemActionUsing('reorder', $callback);
    }

    public function getAddAction(): ?ItemAction
    {
        return $this->getItemAction('add');
    }

    public function getDeleteAction(): ?ItemAction
    {
        return $this->getItemAction('delete');
    }

    public function getReorderAction(): ?ItemAction
    {
        return $this->getItemAction('reorder');
    }

    // ---------------------------------------------------------------- Rows

    /**
     * The state as an ordered list of `['key' => …, 'value' => …]` rows.
     *
     * @return array<int, array{key: string, value: mixed}>
     */
    public function getRows(): array
    {
        $state = $this->getState();

        if (! is_array($state)) {
            return [];
        }

        if (array_is_list($state) && ($state === [] || is_array($state[0]))) {
            return array_values(array_map(static fn (mixed $row): array => [
                'key' => is_array($row) && is_scalar($row['key'] ?? null) ? (string) $row['key'] : '',
                'value' => is_array($row) ? ($row['value'] ?? null) : null,
            ], $state));
        }

        $rows = [];

        foreach ($state as $key => $value) {
            $rows[] = ['key' => (string) $key, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{key: string, value: mixed}>  $rows
     */
    protected function updateRows(array $rows): static
    {
        $old = $this->getState();

        $this->state(array_values($rows));
        $this->callAfterStateUpdated($old);

        return $this;
    }

    protected function getRowIndex(mixed $key, int $count): ?int
    {
        if (is_int($key) || (is_string($key) && preg_match('/^\d+$/', $key) === 1)) {
            $index = (int) $key;

            return $index < $count ? $index : null;
        }

        return null;
    }

    public function addRow(string $key = '', mixed $value = ''): static
    {
        if (! $this->evaluate($this->isAddable)) {
            return $this;
        }

        return $this->updateRows([...$this->getRows(), ['key' => $key, 'value' => $value]]);
    }

    public function deleteRow(mixed $key): static
    {
        $rows = $this->getRows();
        $index = $this->getRowIndex($key, count($rows));

        if ($index === null || ! $this->evaluate($this->isDeletable)) {
            return $this;
        }

        unset($rows[$index]);

        return $this->updateRows($rows);
    }

    /**
     * Put the rows in the order of `$keys` (every row index, once).
     *
     * @param  array<mixed>  $keys
     */
    public function reorderRows(array $keys): static
    {
        $rows = $this->getRows();
        $indexes = array_map(fn (mixed $key): ?int => $this->getRowIndex($key, count($rows)), array_values($keys));

        if (! $this->evaluate($this->isReorderable) || in_array(null, $indexes, true) || count(array_unique($indexes)) !== count($rows)) {
            return $this;
        }

        return $this->updateRows(array_map(static fn (int $index): array => $rows[$index], $indexes));
    }

    // ---------------------------------------------------------------- Actions

    protected function getItemActionNames(): array
    {
        return ['add', 'delete', 'reorder'];
    }

    protected function isItemActionEnabled(string $name): bool
    {
        if ($this->isReadOnly()) {
            return false;
        }

        return match ($name) {
            'add' => (bool) $this->evaluate($this->isAddable),
            'delete' => (bool) $this->evaluate($this->isDeletable),
            'reorder' => (bool) $this->evaluate($this->isReorderable),
            default => false,
        };
    }

    protected function makeItemAction(string $name): ItemAction
    {
        $action = ItemAction::make($name)->color('gray');

        return match ($name) {
            'add' => $action
                ->label(fn (): string => $this->evaluate($this->addActionLabel) ?? __('alfakit::forms.key_value.add'))
                ->icon('plus')
                ->ghost()
                ->defaultAction(function (): void {
                    $this->addRow();
                }),
            'delete' => $action
                ->label(self::itemActionLabel('alfakit::forms.key_value.delete', 'Delete row'))
                ->icon('alfakit::forms.components.key-value.actions.delete')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->deleteRow($arguments['item'] ?? null);
                }),
            'reorder' => $action
                ->label(self::itemActionLabel('alfakit::forms.key_value.reorder', 'Reorder'))
                ->icon('alfakit::forms.components.key-value.actions.reorder')
                ->iconButton()
                ->defaultAction(function (array $arguments): void {
                    $this->reorderRows(is_array($arguments['items'] ?? null) ? $arguments['items'] : []);
                }),
            default => $action,
        };
    }

    /**
     * The add, delete and reorder actions run through the field action endpoint.
     *
     * @return array<\AlfaSix\Alfakit\Actions\Action>
     */
    protected function getExtraFieldActions(): array
    {
        return array_values(array_filter(array_map($this->getItemAction(...), $this->getItemActionNames())));
    }

    /**
     * @return array<string, array<string, mixed>|null>|null
     */
    protected function getKeyValueActionsSchema(): ?array
    {
        $schemas = [];

        foreach ($this->getItemActionNames() as $name) {
            if (isset($this->itemActionModifiers[$name]) && ($action = $this->getItemAction($name)) !== null) {
                $schemas[$name] = $action->arguments(['item' => null])->isVisible() ? $action->toSchema() : null;
            }
        }

        return $schemas === [] ? null : $schemas;
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return [];
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        if (! is_array($state)) {
            return [];
        }

        // The renderer sends rows as [{key, value}] so duplicate/empty keys can be edited freely.
        if (array_is_list($state)) {
            $result = [];

            foreach ($state as $row) {
                if (is_array($row) && filled($row['key'] ?? null)) {
                    $result[(string) $row['key']] = $row['value'] ?? null;
                }
            }

            return $result;
        }

        return array_filter($state, static fn ($key): bool => filled($key), ARRAY_FILTER_USE_KEY);
    }

    protected function getTypeValidationRules(): array
    {
        return ['array'];
    }

    protected function getSchemaProps(): array
    {
        return [
            'keyLabel' => $this->evaluate($this->keyLabel) ?? __('alfakit::forms.key_value.key'),
            'valueLabel' => $this->evaluate($this->valueLabel) ?? __('alfakit::forms.key_value.value'),
            'keyPlaceholder' => $this->evaluate($this->keyPlaceholder),
            'valuePlaceholder' => $this->evaluate($this->valuePlaceholder),
            'addActionLabel' => $this->evaluate($this->addActionLabel) ?? __('alfakit::forms.key_value.add'),
            'addable' => (bool) $this->evaluate($this->isAddable) && (! isset($this->itemActionModifiers['add']) || $this->isItemActionAvailable('add')),
            'deletable' => (bool) $this->evaluate($this->isDeletable) && (! isset($this->itemActionModifiers['delete']) || $this->isItemActionAvailable('delete')),
            'reorderable' => (bool) $this->evaluate($this->isReorderable) && (! isset($this->itemActionModifiers['reorder']) || $this->isItemActionAvailable('reorder')),
            'editableKeys' => (bool) $this->evaluate($this->canEditKeys),
            'editableValues' => (bool) $this->evaluate($this->canEditValues),
            'itemActions' => $this->getKeyValueActionsSchema(),
        ];
    }
}
