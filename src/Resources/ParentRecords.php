<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources;

use Illuminate\Database\Eloquent\Model;

/**
 * The parent records of the nested resource page being served, keyed by parent resource.
 * Kept on the current request (never in static state), so long-running processes can't
 * leak a parent into another request. Nested resources scope their queries and build
 * their URLs from it.
 */
final class ParentRecords
{
    private const ATTRIBUTE = 'alfakit.parent_records';

    /**
     * @param  class-string<\AlfaSix\Alfakit\Resources\Resource>  $resource
     */
    public static function set(string $resource, ?Model $record): void
    {
        $records = self::all();

        if ($record === null) {
            unset($records[$resource]);
        } else {
            $records[$resource] = $record;
        }

        request()->attributes->set(self::ATTRIBUTE, $records);
    }

    /**
     * @param  class-string<\AlfaSix\Alfakit\Resources\Resource>  $resource
     */
    public static function get(string $resource): ?Model
    {
        return self::all()[$resource] ?? null;
    }

    /**
     * @return array<class-string<\AlfaSix\Alfakit\Resources\Resource>, Model>
     */
    public static function all(): array
    {
        $records = request()->attributes->get(self::ATTRIBUTE, []);

        return is_array($records) ? $records : [];
    }

    public static function flush(): void
    {
        request()->attributes->remove(self::ATTRIBUTE);
    }
}
