<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Api;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionMethod;

/**
 * Where an `ApiResource` reads and writes its records: a REST API, a SaaS SDK, a JSON
 * file, an in-memory array. Implement `records()` and `find()` (and `create()`,
 * `update()`, `delete()` for the pages and actions that need them); records are plain
 * arrays. Alfakit wraps them in `ApiRecord` objects for forms, tables and actions.
 *
 * ```php
 * class SupplierSource extends DataSource
 * {
 *     public function records(RecordsQuery $query): iterable
 *     {
 *         return Supplier::all();          // arrays: Alfakit searches, sorts and pages them
 *     }
 *
 *     public function find(string $key): ?array { … }
 *     public function create(array $data): array { … }
 *     public function update(string $key, array $data): array { … }
 *     public function delete(string $key): void { … }
 * }
 * ```
 *
 * Every call can read the current tenant (`getTenant()`) and, in a relation section,
 * the owner record (`getOwnerRecord()`). Failures are normalised: an
 * `Illuminate\Http\Client\RequestException` with a 422 response becomes a
 * `ValidationException` (field errors, see `validationErrors()`), any other HTTP or
 * connection failure a `DataSourceException` that the pages show as an error
 * notification. Set `$cacheSeconds` to cache reads; writes flush the cache.
 */
abstract class DataSource
{
    /** The record attribute holding its key. */
    protected string $keyName = 'id';

    /** @var class-string<ApiRecord> */
    protected string $recordClass = ApiRecord::class;

    /** Cache reads (`records()`, `find()`, `search()`) for this many seconds; `null` never caches. */
    protected ?int $cacheSeconds = null;

    /**
     * API field names that differ from the form's (`'company_name' => 'name'`), used to
     * put API validation errors on the right field.
     *
     * @var array<string, string>
     */
    protected array $errorFields = [];

    protected ?Model $ownerRecord = null;

    // ---------------------------------------------------------------- To implement

    /**
     * The records of a list page. Return the rows (arrays, a collection): the table then
     * searches, sorts and pages them in memory. Or page in the source and return a
     * `LengthAwarePaginator` / `Paginator` of rows, used as is.
     *
     * @return iterable<mixed>
     */
    abstract public function records(RecordsQuery $query): iterable;

    /**
     * One record by key, or `null` when there is none.
     *
     * @return array<string, mixed>|null
     */
    abstract public function find(string $key): ?array;

    /**
     * Create a record from the validated form data and return it as stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        throw new LogicException(static::class.' does not implement create().');
    }

    /**
     * Update a record and return it as stored. Edit pages pass the validated form data;
     * `$record->save()` passes the changed attributes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $key, array $data): array
    {
        throw new LogicException(static::class.' does not implement update().');
    }

    public function delete(string $key): void
    {
        throw new LogicException(static::class.' does not implement delete().');
    }

    /**
     * Records matching a global search, at most `$limit`. Global search is offered for the
     * resource only when this method is overridden.
     *
     * @return iterable<mixed>
     */
    public function search(string $search, int $limit): iterable
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function getRecordKey(array $record): string
    {
        $key = data_get($record, $this->keyName);

        return is_scalar($key) ? (string) $key : '';
    }

    /**
     * The record's title (breadcrumbs, headings, global search). `null` falls back to the
     * resource's `$recordTitleAttribute`, then the key.
     *
     * @param  array<string, mixed>  $record
     */
    public function getRecordTitle(array $record): ?string
    {
        return null;
    }

    /**
     * Field errors from an API's 422 response: `{"errors": {"field": ["message"]}}` (Laravel,
     * Rails) by default, renamed through `$errorFields`. Without field errors the user sees a
     * generic error notification.
     *
     * @return array<string, array<int, string>>
     */
    protected function validationErrors(Response $response): array
    {
        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return [];
        }

        $mapped = [];

        foreach ($errors as $field => $messages) {
            $field = $this->errorFields[$field] ?? (string) $field;
            $mapped[$field] = array_values(array_map(strval(...), array_filter((array) $messages, is_scalar(...))));
        }

        return $mapped;
    }

    // ---------------------------------------------------------------- Context

    public function getKeyName(): string
    {
        return $this->keyName;
    }

    /**
     * The current panel's tenant (`null` without tenancy). Scope every call to it.
     */
    public function getTenant(): ?Model
    {
        return alfakit()->getCurrentPanel()?->getTenant();
    }

    /**
     * The record a relation section belongs to (`null` on resource pages).
     */
    public function getOwnerRecord(): ?Model
    {
        return $this->ownerRecord;
    }

    public function forOwner(?Model $record): static
    {
        $this->ownerRecord = $record;

        return $this;
    }

    /**
     * @param  class-string<ApiRecord>  $class
     */
    public function recordClass(string $class): static
    {
        $this->recordClass = $class;

        return $this;
    }

    public function cacheFor(?int $seconds): static
    {
        $this->cacheSeconds = $seconds;

        return $this;
    }

    public function supportsGlobalSearch(): bool
    {
        return (new ReflectionMethod($this, 'search'))->getDeclaringClass()->getName() !== self::class;
    }

    // ---------------------------------------------------------------- Used by Alfakit

    /**
     * Records for a table, wrapped in `ApiRecord`s: a paginator when the source paged
     * itself, otherwise a collection the table searches, sorts and pages.
     *
     * @return LengthAwarePaginator<array-key, ApiRecord>|Paginator<array-key, ApiRecord>|Collection<array-key, ApiRecord>
     */
    public function getRecords(RecordsQuery $query): LengthAwarePaginator|Paginator|Collection
    {
        $result = $this->remember('records', $query, fn (): mixed => $this->normaliseRows($this->records($query)));

        if ($result instanceof AbstractPaginator) {
            $result->setCollection($result->getCollection()->map(fn (array $row): ApiRecord => $this->makeRecord($row)));

            /** @var LengthAwarePaginator<array-key, ApiRecord>|Paginator<array-key, ApiRecord> $result */
            return $result;
        }

        /** @var array<int, array<string, mixed>> $result */
        return collect($result)->map(fn (array $row): ApiRecord => $this->makeRecord($row))->values();
    }

    public function getRecord(string $key): ?ApiRecord
    {
        if ($key === '') {
            return null;
        }

        try {
            $row = $this->remember('find', $key, fn (): ?array => $this->find($key));
        } catch (DataSourceException $exception) {
            if ($exception->isNotFound()) {
                return null;
            }

            throw $exception;
        }

        return is_array($row) ? $this->makeRecord($row) : null;
    }

    /**
     * @return Collection<int, ApiRecord>
     */
    public function getSearchResults(string $search, int $limit): Collection
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->remember('search', [$search, $limit], fn (): array => array_slice($this->rowsToArrays($this->search($search, $limit)), 0, $limit));

        return collect($rows)->map(fn (array $row): ApiRecord => $this->makeRecord($row));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createRecord(array $data): array
    {
        return $this->write(fn (): array => $this->create($data));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateRecord(string $key, array $data): array
    {
        return $this->write(fn (): array => $this->update($key, $data));
    }

    public function deleteRecord(string $key): void
    {
        $this->write(function () use ($key): bool {
            $this->delete($key);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function makeRecord(array $attributes = [], bool $exists = true): ApiRecord
    {
        $class = $this->recordClass;
        $record = (new $class)->setDataSource($this);
        $record->setRawAttributes($attributes, true);
        $record->exists = $exists;

        return $record;
    }

    /**
     * Forget every cached read of this data source (for the current tenant and owner).
     */
    public function flushCache(): void
    {
        if ($this->cacheSeconds !== null) {
            Cache::forever($this->cacheVersionKey(), (int) Cache::get($this->cacheVersionKey(), 0) + 1);
        }
    }

    // ---------------------------------------------------------------- Internals

    /**
     * Run a call to the source, turning HTTP failures into validation errors (422) or a
     * `DataSourceException`.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function attempt(Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException|DataSourceException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            if ($exception->response->status() === 422 && ($errors = $this->validationErrors($exception->response)) !== []) {
                throw ValidationException::withMessages($errors);
            }

            if ($exception->response->status() === 422) {
                throw new DataSourceException(__('alfakit::panel.resources.api.invalid'), 422, $exception);
            }

            throw DataSourceException::fromThrowable($exception);
        } catch (ConnectionException $exception) {
            throw DataSourceException::fromThrowable($exception);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function write(Closure $callback): mixed
    {
        try {
            return $this->attempt($callback);
        } finally {
            $this->flushCache();
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    protected function remember(string $operation, mixed $arguments, Closure $callback): mixed
    {
        if ($this->cacheSeconds === null) {
            return $this->attempt($callback);
        }

        $key = $this->cacheVersionKey().':'.(int) Cache::get($this->cacheVersionKey(), 0).':'.$operation.':'.md5(serialize($arguments));

        return Cache::remember($key, $this->cacheSeconds, fn (): mixed => $this->attempt($callback));
    }

    protected function cacheVersionKey(): string
    {
        $tenant = $this->getTenant();

        return 'alfakit:data-source:'.static::class
            .':'.($tenant === null ? '-' : $tenant::class.'#'.$tenant->getKey())
            .':'.($this->ownerRecord === null ? '-' : $this->ownerRecord::class.'#'.$this->ownerRecord->getKey());
    }

    /**
     * Rows as plain arrays; a paginator keeps its pagination with array rows (so it can be
     * cached).
     *
     * @param  iterable<mixed>  $rows
     * @return AbstractPaginator<array-key, mixed>|array<int, array<string, mixed>>
     */
    protected function normaliseRows(iterable $rows): AbstractPaginator|array
    {
        if ($rows instanceof LengthAwarePaginator || $rows instanceof Paginator) {
            $rows->setCollection(collect($this->rowsToArrays($rows->items())));

            return $rows;
        }

        return $this->rowsToArrays($rows);
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function rowsToArrays(iterable $rows): array
    {
        $arrays = [];

        foreach ($rows as $row) {
            $row = match (true) {
                $row instanceof Model => $row->attributesToArray(),
                $row instanceof Arrayable => $row->toArray(),
                is_object($row) => get_object_vars($row),
                default => $row,
            };

            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $arrays[] = $row;
            }
        }

        return $arrays;
    }
}
