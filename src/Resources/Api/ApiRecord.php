<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Api;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A record of a data source, never stored in a database.
 *
 * It is an Eloquent model only so that everything built for models (forms filling from
 * a record, infolists, table rows, actions, policies, record URLs) works unchanged.
 * Read it like an array or a model (`$record['name']`, `$record->name`,
 * `$record->toArray()`); `save()` and `delete()` go to the data source, and the key
 * comes from `DataSource::getRecordKey()`. It runs no queries: relationships, scopes and
 * query builder methods are not available.
 *
 * Extend it (and point the resource's `$model` at the subclass) to add accessors, casts
 * or a policy of its own.
 */
class ApiRecord extends Model
{
    /** Route key attribute: resolves to the data source's record key. */
    public const KEY_ATTRIBUTE = '__alfakitKey';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected ?DataSource $dataSource = null;

    public function setDataSource(DataSource $dataSource): static
    {
        $this->dataSource = $dataSource;

        return $this;
    }

    public function getDataSource(): DataSource
    {
        return $this->dataSource ?? throw new LogicException(static::class.' is not bound to a data source.');
    }

    public function getKeyName()
    {
        return $this->dataSource?->getKeyName() ?? parent::getKeyName();
    }

    public function getKey()
    {
        if ($this->dataSource === null) {
            return parent::getKey();
        }

        $key = $this->dataSource->getRecordKey($this->attributes);

        return $key === '' ? null : $key;
    }

    public function getRouteKeyName()
    {
        return self::KEY_ATTRIBUTE;
    }

    public function getAttribute($key)
    {
        if ($key === self::KEY_ATTRIBUTE) {
            return $this->getKey();
        }

        return parent::getAttribute($key);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  bool  $exists
     */
    public function newInstance($attributes = [], $exists = false): static
    {
        $instance = parent::newInstance($attributes, $exists);

        if ($this->dataSource !== null) {
            $instance->setDataSource($this->dataSource);
        }

        return $instance;
    }

    /**
     * Create the record, or update its changed attributes, through the data source.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists && ! $this->isDirty()) {
            return true;
        }

        return $this->persist($this->exists ? $this->getDirty() : $this->getAttributes());
    }

    /**
     * Send `$data` to the data source (`create()` for a new record, `update()` otherwise)
     * and take the attributes it returns.
     *
     * @param  array<string, mixed>  $data
     */
    public function persist(array $data): bool
    {
        $source = $this->getDataSource();

        try {
            $stored = $this->exists ? $source->updateRecord((string) $this->getKey(), $data) : $source->createRecord($data);
        } catch (DataSourceException $exception) {
            throw $exception->notify();
        }

        $this->wasRecentlyCreated = ! $this->exists;
        // A source that answers without the record keeps what was sent.
        $this->setRawAttributes($stored === [] ? [...$this->getAttributes(), ...$data] : $stored, true);
        $this->exists = true;

        return true;
    }

    public function delete(): bool
    {
        if (! $this->exists) {
            return false;
        }

        try {
            $this->getDataSource()->deleteRecord((string) $this->getKey());
        } catch (DataSourceException $exception) {
            throw $exception->notify();
        }

        $this->exists = false;

        return true;
    }

    /**
     * Load the record again from the data source.
     *
     * @param  array<mixed>|string  $with
     */
    public function fresh($with = []): ?static
    {
        $key = $this->getKey();

        if ($key === null) {
            return null;
        }

        $fresh = $this->getDataSource()->getRecord((string) $key);

        if ($fresh === null) {
            return null;
        }

        return $this->newInstance([], true)->setRawAttributes($fresh->getAttributes(), true);
    }

    public function refresh(): static
    {
        $fresh = $this->fresh();

        if ($fresh !== null) {
            $this->setRawAttributes($fresh->getAttributes(), true);
        }

        return $this;
    }
}
