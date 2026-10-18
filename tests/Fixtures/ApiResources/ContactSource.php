<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources;

use AlfaSix\Alfakit\Resources\Api\DataSource;
use AlfaSix\Alfakit\Resources\Api\RecordsQuery;

/**
 * Contacts of a supplier, for the relation-like section on the supplier pages.
 */
class ContactSource extends DataSource
{
    /** @var array<string, array<string, mixed>> */
    public static array $rows = [];

    public static function reset(): void
    {
        static::$rows = [
            'c1' => ['id' => 'c1', 'supplier' => 'acme', 'name' => 'Huda'],
            'c2' => ['id' => 'c2', 'supplier' => 'acme', 'name' => 'Omar'],
            'c3' => ['id' => 'c3', 'supplier' => 'bloom', 'name' => 'Claire'],
        ];
    }

    public function records(RecordsQuery $query): iterable
    {
        $owner = (string) $this->getOwnerRecord()?->getKey();

        return array_values(array_filter(static::$rows, static fn (array $row): bool => $row['supplier'] === $owner && $query->owner?->getKey() === $owner));
    }

    public function find(string $key): ?array
    {
        $row = static::$rows[$key] ?? null;

        return $row !== null && $row['supplier'] === $this->getOwnerRecord()?->getKey() ? $row : null;
    }

    public function create(array $data): array
    {
        $id = 'c'.(count(static::$rows) + 1);

        return static::$rows[$id] = ['id' => $id, 'supplier' => $this->getOwnerRecord()?->getKey(), ...$data];
    }

    public function update(string $key, array $data): array
    {
        return static::$rows[$key] = [...static::$rows[$key], ...$data];
    }

    public function delete(string $key): void
    {
        unset(static::$rows[$key]);
    }
}
