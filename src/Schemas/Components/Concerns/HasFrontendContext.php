<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in context for custom frontend components: the operation, the record's key and the
 * record attributes (or any other data) the component needs. Nothing is sent unless the
 * component calls `exposeContext()` (or sets `$exposesContext = true`), so records never
 * reach the browser by accident.
 *
 * ```php
 * MyComponent::make()->exposeContext(record: ['name', 'status'])
 * MyComponent::make()->exposeContext(data: fn (?Post $record) => ['words' => str_word_count($record?->body ?? '')])
 * ```
 */
trait HasFrontendContext
{
    protected bool|Closure $exposesContext = false;

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $exposedRecordAttributes = null;

    /** @var array<string, mixed>|Closure|null */
    protected array|Closure|null $exposedContextData = null;

    /**
     * @param  array<int, string>|Closure|null  $record  Record attributes sent to the browser
     * @param  array<string, mixed>|Closure|null  $data  Extra values, closures get `$record`, `$operation`, `$get`…
     */
    public function exposeContext(bool|Closure $condition = true, array|Closure|null $record = null, array|Closure|null $data = null): static
    {
        $this->exposesContext = $condition;

        if ($record !== null) {
            $this->exposedRecordAttributes = $record;
        }

        if ($data !== null) {
            $this->exposedContextData = $data;
        }

        return $this;
    }

    public function exposesContext(): bool
    {
        return (bool) $this->evaluate($this->exposesContext);
    }

    /**
     * `context` for the schema: `{ operation, recordKey, record, data, basePath }`. `basePath`
     * is the container's state path, which relative `get()` paths resolve from, as `$get()`.
     *
     * @return array<string, mixed>|null
     */
    public function getFrontendContext(): ?array
    {
        if (! $this->exposesContext()) {
            return null;
        }

        $record = $this->getRecord();
        $attributes = $this->evaluate($this->exposedRecordAttributes);

        return [
            'operation' => $this->getOperation(),
            'recordKey' => $record instanceof Model ? $record->getKey() : null,
            'record' => $record instanceof Model && is_array($attributes) && $attributes !== []
                ? $this->serializeRecordAttributes($record, $attributes)
                : null,
            'data' => $this->evaluate($this->exposedContextData) ?: null,
            'basePath' => $this->hasContainer() ? $this->getContainer()->getStatePath() : null,
        ];
    }

    /**
     * Each listed attribute, read like `data_get()` so `author.name` follows relationships.
     *
     * @param  array<int, string>  $attributes
     * @return array<string, mixed>
     */
    protected function serializeRecordAttributes(Model $record, array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $value = data_get($record, $attribute);

            if ($value instanceof Model) {
                $value = $value->toArray();
            }

            data_set($values, $attribute, $value);
        }

        return $values;
    }
}
