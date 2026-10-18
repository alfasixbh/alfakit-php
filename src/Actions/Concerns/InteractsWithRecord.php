<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The record an action works on: a model, or an array row of a table with custom
 * `records()`. Rows carry no key of their own, so the table hands it over with
 * `recordKey()`; closures receive the row as `$record` (type it `array $record`).
 */
trait InteractsWithRecord
{
    /** @var Model|array<mixed>|Closure|null */
    protected Model|array|Closure|null $record = null;

    protected mixed $recordKey = null;

    /** @var class-string<Model>|Closure|null */
    protected string|Closure|null $model = null;

    /**
     * @param  Model|array<mixed>|Closure|null  $record
     */
    public function record(Model|array|Closure|null $record): static
    {
        $this->record = $record;

        if (property_exists($this, 'authorizationResponse')) {
            $this->authorizationResponse = null;
        }

        if (! is_array($record)) {
            $this->recordKey = null;
        }

        if (method_exists($this, 'flushForm')) {
            $this->flushForm();
        }

        return $this;
    }

    /**
     * The key of an array row (custom `records()`), which the renderer posts back to run
     * the action. Models use their own key.
     */
    public function recordKey(mixed $key): static
    {
        $this->recordKey = $key;

        return $this;
    }

    /**
     * @param  class-string<Model>|Closure|null  $model
     */
    public function model(string|Closure|null $model): static
    {
        $this->model = $model;

        return $this;
    }

    /**
     * @return Model|array<mixed>|null
     */
    public function getRecord(): Model|array|null
    {
        $record = $this->evaluate($this->record);

        // Actions inside another action's modal work on the parent's record.
        if ($record === null && property_exists($this, 'parentAction') && $this->parentAction !== null) {
            return $this->parentAction->getRecord();
        }

        return $record;
    }

    /**
     * The record when it is a model (closures typed `Model $record` get null for array rows).
     */
    public function getRecordModel(): ?Model
    {
        $record = $this->getRecord();

        return $record instanceof Model ? $record : null;
    }

    public function getRecordKey(): mixed
    {
        $record = $this->getRecord();

        if ($record instanceof Model) {
            return $record->getKey();
        }

        if ($record === null) {
            return null;
        }

        if ($this->recordKey === null && property_exists($this, 'parentAction') && $this->parentAction !== null) {
            return $this->parentAction->getRecordKey();
        }

        return $this->recordKey ?? data_get($record, 'id');
    }

    /**
     * @return class-string<Model>|null
     */
    public function getModel(): ?string
    {
        $model = $this->evaluate($this->model);

        if ($model !== null) {
            return $model;
        }

        $record = $this->getRecordModel();

        return $record === null ? null : $record::class;
    }

    public function getRecordTitle(): ?string
    {
        $record = $this->getRecord();

        if ($record === null) {
            return null;
        }

        if (method_exists($this, 'getRecordTitleUsing') && ($title = $this->getRecordTitleUsing()) !== null) {
            return $title;
        }

        foreach (['title', 'name', 'label', 'email'] as $attribute) {
            $value = $record instanceof Model ? $record->getAttribute($attribute) : data_get($record, $attribute);

            if (filled($value) && is_scalar($value)) {
                return (string) $value;
            }
        }

        $key = $this->getRecordKey();

        return is_scalar($key) ? (string) $key : null;
    }
}
