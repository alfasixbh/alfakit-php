<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * A record action that also works on the table's selection: `accessSelectedRecords()`
 * makes `$selectedRecords` available to the action's closures (`action()`, `before()`,
 * `after()`). The renderer sends the selected keys as the reserved
 * `alfakitSelectedRecords` argument; they are resolved within the table's own query and
 * its selectability rules, never trusted as they are.
 */
trait CanAccessSelectedRecords
{
    /** Argument the renderer sends the selected keys in. */
    public const SELECTED_RECORDS_ARGUMENT = 'alfakitSelectedRecords';

    /** Argument flagging "every filtered record" selections. */
    public const ALL_SELECTED_RECORDS_ARGUMENT = 'alfakitAllSelectedRecords';

    protected bool|Closure $canAccessSelectedRecords = false;

    protected ?Table $selectionTable = null;

    /** @var Collection<array-key, mixed>|null */
    protected ?Collection $resolvedSelectedRecords = null;

    public function accessSelectedRecords(bool|Closure $condition = true): static
    {
        $this->canAccessSelectedRecords = $condition;

        return $this;
    }

    public function canAccessSelectedRecords(): bool
    {
        return (bool) $this->evaluate($this->canAccessSelectedRecords);
    }

    public function selectionTable(?Table $table): static
    {
        $this->selectionTable = $table;
        $this->resolvedSelectedRecords = null;

        return $this;
    }

    /**
     * The records selected in the table when the action ran (empty unless the action
     * `accessSelectedRecords()`).
     *
     * @return Collection<array-key, mixed>
     */
    public function getSelectedRecords(): Collection
    {
        if ($this->resolvedSelectedRecords !== null) {
            return $this->resolvedSelectedRecords;
        }

        if (! $this->canAccessSelectedRecords() || $this->selectionTable === null) {
            return new EloquentCollection;
        }

        $arguments = $this->getArguments();
        $keys = $arguments[self::SELECTED_RECORDS_ARGUMENT] ?? [];
        $all = filter_var($arguments[self::ALL_SELECTED_RECORDS_ARGUMENT] ?? false, FILTER_VALIDATE_BOOL);

        return $this->resolvedSelectedRecords = $this->selectionTable->getSelectedRecords(is_array($keys) ? $keys : [], $all);
    }
}
