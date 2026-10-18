<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Display components whose state reads through a *-to-many relationship (`tags.name`)
 * show one item per related record. Closures such as `color()`, `icon()` and `url()` are
 * then evaluated once per item, with `$state` set to the item and `$relatedRecord` to the
 * model the item was read from (the `Tag`):
 *
 * ```php
 * TextEntry::make('tags.name')->badge()->color(fn (Tag $relatedRecord) => $relatedRecord->color)
 * ```
 */
trait EvaluatesPerRelatedRecord
{
    /**
     * The items of a relationship list state with the record each was read from, or null
     * when the state is not read through a *-to-many relationship.
     *
     * @return list<array{0: mixed, 1: Model|null}>|null
     */
    public function getRelatedRecordItems(): ?array
    {
        $record = $this->getRecord();
        $name = $this->getName();

        if (! $record instanceof Model || ! str_contains($name, '.') || (property_exists($this, 'getStateUsing') && $this->getStateUsing !== null)) {
            return null;
        }

        $isList = false;
        $items = self::collectRelatedRecordItems($record, explode('.', $name), $record, $isList);

        return $isList ? $items : null;
    }

    /**
     * Evaluate a property once per related item. Returns `[single, perItem]`: one value
     * when every item agrees, the per-item values (aligned with the items) otherwise.
     * Null when the property is not a closure or the state is not a relationship list.
     *
     * @return array{0: mixed, 1: list<mixed>|null}|null
     */
    protected function evaluatePerRelatedRecord(mixed $value): ?array
    {
        if (! $value instanceof Closure || ($items = $this->getRelatedRecordItems()) === null) {
            return null;
        }

        $values = [];

        foreach ($items as [$item, $relatedRecord]) {
            $values[] = $this->evaluate(
                $value,
                ['state' => $item, 'relatedRecord' => $relatedRecord],
                $relatedRecord !== null ? [$relatedRecord::class => $relatedRecord] : [],
            );
        }

        $distinct = array_unique(array_map(static fn (mixed $value): string => serialize($value), $values));

        if ($values === []) {
            return [null, null];
        }

        return count($distinct) === 1 ? [$values[0], null] : [null, $values];
    }

    /**
     * Walk the path like `RecordState::get()`, keeping the model that owns each value.
     *
     * @param  array<int, string>  $segments
     * @return list<array{0: mixed, 1: Model|null}>
     */
    private static function collectRelatedRecordItems(mixed $current, array $segments, ?Model $owner, bool &$isList): array
    {
        foreach ($segments as $index => $segment) {
            if ($current === null) {
                return [];
            }

            if ($current instanceof Collection || (is_array($current) && array_is_list($current) && ($current[0] ?? null) instanceof Model)) {
                $isList = true;
                $remaining = array_slice($segments, $index);
                $items = [];

                foreach ($current as $item) {
                    array_push($items, ...self::collectRelatedRecordItems($item, $remaining, $item instanceof Model ? $item : $owner, $isList));
                }

                return $items;
            }

            if ($current instanceof Model) {
                $owner = $current;
            }

            $current = data_get($current, $segment);
        }

        if ($current instanceof Collection) {
            $current = $current->all();
        }

        if (is_array($current)) {
            $items = [];

            foreach (collect($current)->flatten()->all() as $value) {
                if ($value !== null && $value !== '') {
                    $items[] = [$value, $value instanceof Model ? $value : $owner];
                }
            }

            return $items;
        }

        return $current === null || $current === '' ? [] : [[$current, $owner]];
    }
}
