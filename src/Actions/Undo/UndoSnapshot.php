<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Undo;

use Illuminate\Database\Eloquent\Model;

/**
 * Attribute snapshots for undoing an edit: the raw values a save changed, before and after,
 * read from the database so casts and mutators don't get in the way.
 */
final class UndoSnapshot
{
    /**
     * The record's stored attributes (raw).
     *
     * @return array<string, mixed>
     */
    public static function read(Model $record): array
    {
        $fresh = $record->newQueryWithoutScopes()->whereKey($record->getKey())->first();

        return $fresh === null ? [] : $fresh->getAttributes();
    }

    /**
     * What a save changed: `before` and `after` values of the tracked attributes that differ,
     * or null when nothing changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string>|null  $tracked
     * @return array{before: array<string, mixed>, after: array<string, mixed>}|null
     */
    public static function diff(Model $record, array $before, ?array $tracked = null): ?array
    {
        $after = self::read($record);
        $ignored = array_filter([$record->getKeyName(), $record->getCreatedAtColumn(), $record->getUpdatedAtColumn()]);
        $changedBefore = [];
        $changedAfter = [];

        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $attribute) {
            if (in_array($attribute, $ignored, true) || ($tracked !== null && ! in_array($attribute, $tracked, true))) {
                continue;
            }

            if (! self::same($before[$attribute] ?? null, $after[$attribute] ?? null)) {
                $changedBefore[$attribute] = $before[$attribute] ?? null;
                $changedAfter[$attribute] = $after[$attribute] ?? null;
            }
        }

        return $changedBefore === [] ? null : ['before' => $changedBefore, 'after' => $changedAfter];
    }

    /**
     * Attributes of `$expected` whose stored value is different now (someone changed the
     * record since the action ran).
     *
     * @param  array<string, mixed>  $expected
     * @return array<int, string>
     */
    public static function conflicts(Model $record, array $expected): array
    {
        $current = self::read($record);
        $conflicts = [];

        foreach ($expected as $attribute => $value) {
            if (! self::same($current[$attribute] ?? null, $value)) {
                $conflicts[] = (string) $attribute;
            }
        }

        return $conflicts;
    }

    /**
     * Put the `before` values back, as raw attributes (model events still fire).
     *
     * @param  array<string, mixed>  $before
     */
    public static function restore(Model $record, array $before): void
    {
        $fresh = $record->newQueryWithoutScopes()->whereKey($record->getKey())->firstOrFail();
        $fresh->setRawAttributes([...$fresh->getAttributes(), ...$before]);
        $fresh->save();
    }

    /**
     * Database drivers return numbers as strings or integers: compare loosely by value.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_scalar($a) && is_scalar($b)) {
            return (string) $a === (string) $b;
        }

        return $a == $b;
    }
}
