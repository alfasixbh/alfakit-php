<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Api;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

/**
 * A data source for a typical JSON REST API:
 *
 * | Call                  | Request                                                       |
 * | --------------------- | ------------------------------------------------------------- |
 * | `records()`           | `GET {endpoint}?search=…&sort=…&direction=…&page=…&per_page=…` |
 * | `find($key)`          | `GET {endpoint}/{key}` (404 means "no record")                |
 * | `create($data)`       | `POST {endpoint}`                                             |
 * | `update($key, $data)` | `PATCH {endpoint}/{key}` (`$updateMethod`)                    |
 * | `delete($key)`        | `DELETE {endpoint}/{key}`                                     |
 *
 * ```php
 * class SupplierSource extends HttpDataSource
 * {
 *     protected string $endpoint = '/suppliers';
 *
 *     protected function getBaseUrl(): string
 *     {
 *         return config('services.erp.url');
 *     }
 *
 *     protected function request(): PendingRequest
 *     {
 *         return parent::request()->withToken(config('services.erp.token'));
 *     }
 * }
 * ```
 *
 * List responses are read from `$recordsKey` (`data`) and `$totalKey` (`total`, dot
 * notation such as `meta.total` works); a response without a total is treated as the
 * complete list. Override `mapRecord()` / `mapPayload()` to translate between the API's
 * shape and your form fields, and `listParameters()` for other parameter names.
 */
abstract class HttpDataSource extends DataSource
{
    protected string $baseUrl = '';

    /** The collection's path, e.g. `/suppliers`. */
    protected string $endpoint = '';

    /** Where the rows are in a list response; `null` when the body is the list. */
    protected ?string $recordsKey = 'data';

    /** Where the total count is in a list response; `null` when the API doesn't page. */
    protected ?string $totalKey = 'total';

    /** Where the record is in a single-record response; `null` when the body is the record. */
    protected ?string $recordKey = null;

    protected string $updateMethod = 'patch';

    protected int $timeout = 10;

    /**
     * Query parameter names of the list request (`null` leaves one out).
     *
     * @var array{search?: string|null, sort?: string|null, direction?: string|null, page?: string|null, perPage?: string|null}
     */
    protected array $parameters = [
        'search' => 'search',
        'sort' => 'sort',
        'direction' => 'direction',
        'page' => 'page',
        'perPage' => 'per_page',
    ];

    protected function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * The request every call starts from: base URL, JSON, timeout. Add authentication and
     * tenant headers here.
     */
    protected function request(): PendingRequest
    {
        return Http::baseUrl($this->getBaseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout);
    }

    /**
     * @return iterable<mixed>
     */
    public function records(RecordsQuery $query): iterable
    {
        $response = $this->request()->get($this->endpoint, $this->listParameters($query))->throw();

        $rows = $this->recordsKey === null ? $response->json() : $response->json($this->recordsKey);
        $rows = array_map(fn (mixed $row): array => $this->mapRecord((array) $row), is_array($rows) ? array_values($rows) : []);
        $total = $this->totalKey === null ? null : $response->json($this->totalKey);

        if (! is_numeric($total) || ! $query->isPaginated()) {
            return $rows;
        }

        return new LengthAwarePaginator($rows, (int) $total, (int) $query->perPage, $query->page);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $response = $this->request()->get($this->recordUrl($key));

        if ($response->status() === 404) {
            return null;
        }

        return $this->mapRecord($this->recordFrom($response->throw()));
    }

    public function create(array $data): array
    {
        $response = $this->request()->post($this->endpoint, $this->mapPayload($data))->throw();

        return $this->mapRecord($this->recordFrom($response) ?: $data);
    }

    public function update(string $key, array $data): array
    {
        $response = $this->request()->send(strtoupper($this->updateMethod), $this->recordUrl($key), ['json' => $this->mapPayload($data)])->throw();

        return $this->mapRecord($this->recordFrom($response));
    }

    public function delete(string $key): void
    {
        $response = $this->request()->delete($this->recordUrl($key));

        // Already gone is as good as deleted.
        if ($response->status() !== 404) {
            $response->throw();
        }
    }

    /**
     * Query parameters of the list request: search, sort, page and every filter with a
     * value (`?status=active`).
     *
     * @return array<string, mixed>
     */
    protected function listParameters(RecordsQuery $query): array
    {
        $names = $this->parameters;
        $parameters = [];

        $add = static function (?string $name, mixed $value) use (&$parameters): void {
            if ($name !== null && $value !== null && $value !== '') {
                $parameters[$name] = $value;
            }
        };

        $add($names['search'] ?? null, $query->search);
        $add($names['sort'] ?? null, $query->sortColumn);
        $add($names['direction'] ?? null, $query->sortColumn === null ? null : $query->sortDirection);

        if ($query->isPaginated()) {
            $add($names['page'] ?? null, $query->page);
            $add($names['perPage'] ?? null, $query->perPage);
        }

        foreach ($query->filters as $name => $state) {
            $add($name, $query->filter($name) ?? $query->filter($name, 'values') ?? $query->filter($name, 'isActive'));
        }

        return $parameters;
    }

    protected function recordUrl(string $key): string
    {
        return rtrim($this->endpoint, '/').'/'.rawurlencode($key);
    }

    /**
     * @return array<string, mixed>
     */
    protected function recordFrom(Response $response): array
    {
        $record = $this->recordKey === null ? $response->json() : $response->json($this->recordKey);

        return is_array($record) ? $record : [];
    }

    /**
     * An API row as the record forms, tables and infolists read.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function mapRecord(array $row): array
    {
        return $row;
    }

    /**
     * Validated form data as the API expects it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mapPayload(array $data): array
    {
        return $data;
    }
}
