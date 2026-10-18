<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support;

use BackedEnum;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Htmlable;
use JsonSerializable;

/**
 * Normalises PHP values into the JSON-safe shapes the frontend schema contract expects.
 */
final class Serializable
{
    public static function value(mixed $value): mixed
    {
        // Hot path (every cell, column and component of a page): scalars and arrays of
        // scalars are returned without a call per item.
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($item !== null && ! is_scalar($item)) {
                    $value[$key] = self::value($item);
                }
            }

            return $value;
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof Htmlable => $value->toHtml(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof Arrayable => self::value($value->toArray()),
            default => $value,
        };
    }

    /**
     * Drop null entries so the serialized schema stays compact.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function compact(array $schema): array
    {
        /** @var array<string, mixed> $schema */
        $schema = self::value($schema);

        foreach ($schema as $key => $value) {
            if ($value === null) {
                unset($schema[$key]);
            }
        }

        return $schema;
    }
}
