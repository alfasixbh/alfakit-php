<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reads dotted attribute paths from records, flattening through *-to-many relationships:
 * `author.name` → string, `tags.name` → list of names, `comments.author.name` → list.
 */
final class RecordState
{
    public static function get(mixed $target, string $path): mixed
    {
        if ($path === '') {
            return $target;
        }

        $segments = explode('.', $path);
        $current = $target;

        foreach ($segments as $index => $segment) {
            if ($current === null) {
                return null;
            }

            if ($current instanceof Collection || (is_array($current) && array_is_list($current) && ($current[0] ?? null) instanceof Model)) {
                $remaining = implode('.', array_slice($segments, $index));

                return collect($current)
                    ->map(static fn (mixed $item): mixed => self::get($item, $remaining))
                    ->flatten()
                    ->filter(static fn (mixed $value): bool => $value !== null)
                    ->values()
                    ->all();
            }

            $current = data_get($current, $segment);
        }

        return $current;
    }
}
