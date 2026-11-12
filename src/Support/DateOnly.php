<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Attributes that hold a calendar day, not a moment.
 */
class DateOnly
{
    /**
     * Whether the record casts `$attribute` to a date (`date`, `immutable_date`, with or
     * without a format). Such a value is midnight in the app's timezone: converted to
     * another timezone it would show the day before (or after).
     */
    public static function is(mixed $record, string $attribute): bool
    {
        if (! $record instanceof Model || str_contains($attribute, '.')) {
            return false;
        }

        $cast = $record->getCasts()[$attribute] ?? null;

        return is_string($cast) && preg_match('/^(immutable_)?date(:|$)/', $cast) === 1;
    }
}
