<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Api;

use Illuminate\Database\Eloquent\Model;

/**
 * What a list page (or relation section) asks a data source for: the table's search,
 * sort, filters, active tab and page, plus the current tenant and owner record.
 *
 * Every value comes from the request. Treat them like any other user input: pass them
 * on as query parameters, never into a URL path or a raw query unescaped.
 */
final readonly class RecordsQuery
{
    /**
     * @param  array<string, string>  $columnSearches  Individual column searches (`column => term`)
     * @param  array<string, array<string, mixed>>  $filters  Filter states (`filter => ['value' => …]`)
     * @param  int|null  $perPage  `null` when the table shows every record
     */
    public function __construct(
        public ?string $search = null,
        public array $columnSearches = [],
        public ?string $sortColumn = null,
        public ?string $sortDirection = null,
        public array $filters = [],
        public int $page = 1,
        public ?int $perPage = 10,
        public ?string $tab = null,
        public ?Model $tenant = null,
        public ?Model $owner = null,
    ) {}

    /**
     * One value of a filter's state: `$query->filter('status')` reads `['value' => …]`,
     * `$query->filter('created', 'from')` another key.
     */
    public function filter(string $name, string $key = 'value'): mixed
    {
        $value = $this->filters[$name][$key] ?? null;

        return $value === '' || $value === [] ? null : $value;
    }

    /**
     * The zero-based offset of the first record of the page.
     */
    public function offset(): int
    {
        return $this->perPage === null ? 0 : max(0, ($this->page - 1) * $this->perPage);
    }

    public function isPaginated(): bool
    {
        return $this->perPage !== null;
    }

    public function isDescending(): bool
    {
        return $this->sortDirection === 'desc';
    }
}
