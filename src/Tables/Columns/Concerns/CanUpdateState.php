<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Concerns;

use AlfaSix\Alfakit\Support\Labels;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

/**
 * Inline-editable columns: the renderer posts the new value to the table cell endpoint.
 */
trait CanUpdateState
{
    protected ?Closure $updateStateUsing = null;

    /** @var array<mixed>|Closure */
    protected array|Closure $rules = [];

    /** @var array<Closure> */
    protected array $beforeStateUpdated = [];

    /** @var array<Closure> */
    protected array $afterStateUpdated = [];

    protected bool|Closure $isDisabled = false;

    /** Null: the column's default (inline-editable columns yes, text columns no). */
    protected bool|Closure|null $isEditable = null;

    public function updateStateUsing(?Closure $callback): static
    {
        $this->updateStateUsing = $callback;

        return $this;
    }

    /**
     * @param  array<mixed>|Closure  $rules
     */
    public function rules(array|Closure $rules): static
    {
        $this->rules = $rules;

        return $this;
    }

    public function beforeStateUpdated(Closure $callback): static
    {
        $this->beforeStateUpdated[] = $callback;

        return $this;
    }

    public function afterStateUpdated(Closure $callback): static
    {
        $this->afterStateUpdated[] = $callback;

        return $this;
    }

    public function disabled(bool|Closure $condition = true): static
    {
        $this->isDisabled = $condition;

        return $this;
    }

    public function isDisabled(): bool
    {
        return (bool) $this->evaluate($this->isDisabled);
    }

    /**
     * Disabled for the cell being rendered: `disabled()`, or the user may not `update` this
     * record (the server refuses that edit anyway; the control shouldn't look editable).
     */
    public function isDisabledForRecord(): bool
    {
        if ($this->isDisabled()) {
            return true;
        }

        $record = $this->getRecord();
        $table = $this->getTable();

        return $record instanceof Model && $table !== null && ! $table->authorizes('update', $record);
    }

    /**
     * Whether the cells can be edited in the table's spreadsheet mode (`Table::spreadsheet()`).
     * Inline-editable columns are by default (`editable(false)` leaves one out); a
     * `TextColumn` opts in with `editable()`, with its own `rules()`.
     */
    public function editable(bool|Closure $condition = true): static
    {
        $this->isEditable = $condition;

        return $this;
    }

    public function isEditable(): bool
    {
        $condition = $this->evaluate($this->isEditable);

        return $condition === null ? $this->isEditableByDefault() : (bool) $condition;
    }

    protected function isEditableByDefault(): bool
    {
        return true;
    }

    /**
     * Run the column's validation for a new value of the record's cell, without saving.
     *
     * @param  Model|array<mixed>  $record
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateState(Model|array $record, mixed $state): void
    {
        $this->record($record);

        abort_unless($state === null || is_scalar($state), 422);

        $rules = $this->evaluate($this->rules) ?? [];

        if ($rules !== []) {
            Validator::make(['state' => $state], ['state' => $rules], [], ['state' => Labels::forSentence($this->getLabel())])->validate();
        }
    }

    /**
     * Validate and persist a new value for the record. Array rows of custom `records()`
     * have nothing to save to, so they need `updateStateUsing()`.
     *
     * @param  Model|array<mixed>  $record
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateState(Model|array $record, mixed $state): mixed
    {
        $this->record($record);

        abort_if(! $record instanceof Model && $this->updateStateUsing === null, 400, "Column [{$this->getName()}] edits rows of custom records: add updateStateUsing() to save them.");

        if ($this->isDisabled()) {
            abort(403);
        }

        // A cell holds one value; arrays/objects only come from tampered requests and
        // would otherwise reach string casts or the database driver and fail with a 500.
        $this->validateState($record, $state);
        $this->record($record);

        $state = $this->mutateStateForUpdate($state);

        foreach ($this->beforeStateUpdated as $callback) {
            $this->evaluate($callback, ['state' => $state]);
        }

        if ($this->updateStateUsing !== null) {
            $this->evaluate($this->updateStateUsing, ['state' => $state]);
        } else {
            $record->setAttribute($this->getName(), $state);
            $record->save();
        }

        foreach ($this->afterStateUpdated as $callback) {
            $this->evaluate($callback, ['state' => $state]);
        }

        $this->record(null);

        return $state;
    }

    protected function mutateStateForUpdate(mixed $state): mixed
    {
        return $state;
    }
}
