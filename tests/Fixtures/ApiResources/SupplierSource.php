<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ApiResources;

use AlfaSix\Alfakit\Resources\Api\DataSource;
use AlfaSix\Alfakit\Resources\Api\DataSourceException;
use AlfaSix\Alfakit\Resources\Api\RecordsQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * An in-memory data source: returns every row and lets the table search, sort and page.
 */
class SupplierSource extends DataSource
{
    /** @var array<string, array<string, mixed>> */
    public static array $rows = [];

    /** @var array<int, array{0: string, 1: ?Model}> */
    public static array $calls = [];

    public static bool $fail = false;

    public static function reset(): void
    {
        static::$fail = false;
        static::$calls = [];
        static::$rows = [
            'acme' => ['id' => 'acme', 'name' => 'Acme Oils', 'country' => 'Bahrain', 'rating' => 4, 'locked' => false],
            'bloom' => ['id' => 'bloom', 'name' => 'Bloom Florals', 'country' => 'France', 'rating' => 5, 'locked' => false],
            'cedar' => ['id' => 'cedar', 'name' => 'Cedar & Co', 'country' => 'Lebanon', 'rating' => 3, 'locked' => true],
        ];
    }

    protected function track(string $call): void
    {
        static::$calls[] = [$call, $this->getTenant()];

        if (static::$fail) {
            throw new DataSourceException('The supplier service is down.');
        }
    }

    public function records(RecordsQuery $query): iterable
    {
        $this->track('records');

        $rows = array_values(static::$rows);

        if (($country = $query->filter('country')) !== null) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['country'] === $country));
        }

        return $rows;
    }

    public function find(string $key): ?array
    {
        $this->track('find');

        return static::$rows[$key] ?? null;
    }

    public function create(array $data): array
    {
        $this->track('create');

        foreach (static::$rows as $row) {
            if ($row['name'] === $data['name']) {
                throw ValidationException::withMessages(['name' => 'A supplier with this name exists.']);
            }
        }

        $id = strtolower(explode(' ', (string) $data['name'])[0]);

        return static::$rows[$id] = ['id' => $id, 'locked' => false, ...$data];
    }

    public function update(string $key, array $data): array
    {
        $this->track('update');

        return static::$rows[$key] = [...static::$rows[$key], ...$data];
    }

    public function delete(string $key): void
    {
        $this->track('delete');

        unset(static::$rows[$key]);
    }

    public function search(string $search, int $limit): iterable
    {
        $this->track('search');

        return array_filter(static::$rows, static fn (array $row): bool => mb_stripos((string) $row['name'], $search) !== false);
    }
}
