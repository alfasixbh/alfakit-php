<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Realtime;

use Illuminate\Database\Eloquent\Model;

/**
 * The version of a record for optimistic concurrency: a keyed hash of its stored
 * attributes. Any change saved to the row changes it, also within the same second (unlike
 * `updated_at`), and it reveals nothing about the values.
 */
final class RecordVersion
{
    /** Request input the Edit page's save sends the loaded version in. */
    public const INPUT = 'recordVersion';

    /** Validation error key of a refused save; the renderers show the conflict banner. */
    public const CONFLICT_ERROR = 'alfakitConflict';

    public static function of(Model $record): string
    {
        $attributes = $record->getRawOriginal();
        ksort($attributes);

        $encoded = json_encode(array_map(static fn (mixed $value): mixed => is_scalar($value) || $value === null ? (string) $value : $value, $attributes), JSON_PARTIAL_OUTPUT_ON_ERROR);

        return substr(hash_hmac('sha256', $record::class.'|'.$encoded, (string) config('app.key')), 0, 24);
    }

    public static function matches(Model $record, string $version): bool
    {
        return hash_equals(self::of($record), $version);
    }
}
