<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\Concerns;

use AlfaSix\Alfakit\Resources\Api\DataSourceException;
use AlfaSix\Alfakit\Resources\ApiResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Record pages of an `ApiResource`: the record is loaded through the data source; when
 * the source fails the user is sent back to the list with an error notification.
 */
trait InteractsWithApiRecord
{
    /**
     * @return class-string<ApiResource>
     */
    public static function getResource(): string
    {
        /** @var class-string<ApiResource> */
        return static::$resource;
    }

    protected function resolveRecord(mixed $key): Model
    {
        try {
            return parent::resolveRecord($key);
        } catch (DataSourceException $exception) {
            $exception->notify();

            $resource = static::getResource();

            throw new HttpResponseException(redirect()->to($resource::hasPage('index') ? $resource::getUrl() : url()->previous()));
        }
    }
}
