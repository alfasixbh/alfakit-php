<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Resources\Api\DataSourceException;
use AlfaSix\Alfakit\Resources\Api\RecordsQuery;
use AlfaSix\Alfakit\Resources\ApiResource;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The list page of an `ApiResource`: the resource's table over the data source's
 * `records()`, with search, sort, filters, the active tab and the page passed in a
 * `RecordsQuery`. Record actions and bulk actions resolve their records by key through
 * the data source's `find()`.
 */
class ListApiRecords extends ListRecords
{
    /**
     * @return class-string<ApiResource>
     */
    public static function getResource(): string
    {
        /** @var class-string<ApiResource> */
        return static::$resource;
    }

    public function table(Table $table): Table
    {
        $resource = static::getResource();

        return $resource::table(
            $table
                ->records(fn (?string $search, array $columnSearches, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage, array $filters): mixed => $resource::getTableRecords(new RecordsQuery(
                    search: $search,
                    columnSearches: $columnSearches,
                    sortColumn: $sortColumn,
                    sortDirection: $sortDirection,
                    filters: $filters,
                    page: $page,
                    perPage: is_numeric($recordsPerPage) ? (int) $recordsPerPage : null,
                    tab: $this->getActiveTab(),
                    tenant: $this->getPanelTenant(),
                )))
                ->resolveRecordUsing(function (string $key) use ($resource): ?Model {
                    try {
                        return $resource::resolveRecordRouteBinding($key);
                    } catch (DataSourceException $exception) {
                        $exception->notify();

                        return null;
                    }
                })
                ->modelLabel($resource::getModelLabel())
                ->pluralModelLabel($resource::getPluralModelLabel())
                ->recordUrl(fn (mixed $record): ?string => $record instanceof Model ? $this->getTableRecordUrl($record) : null),
        );
    }
}
