<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources;

use AlfaSix\Alfakit\GlobalSearch\GlobalSearchResult;
use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use AlfaSix\Alfakit\Resources\Api\DataSource;
use AlfaSix\Alfakit\Resources\Api\DataSourceException;
use AlfaSix\Alfakit\Resources\Api\RecordsQuery;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\ModelLabel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

/**
 * A resource over records that don't live in your database: a REST API, a SaaS SDK, a
 * file. Point it at a `DataSource` and use the `*ApiRecord(s)` pages:
 *
 * ```php
 * class SupplierResource extends ApiResource
 * {
 *     protected static ?string $dataSource = SupplierSource::class;
 *
 *     protected static ?string $recordTitleAttribute = 'name';
 *
 *     public static function getPages(): array
 *     {
 *         return [
 *             'index' => Pages\ListSuppliers::route('/'),       // extends ListApiRecords
 *             'create' => Pages\CreateSupplier::route('/create'), // extends CreateApiRecord
 *             'view' => Pages\ViewSupplier::route('/{record}'),   // extends ViewApiRecord
 *             'edit' => Pages\EditSupplier::route('/{record}/edit'), // extends EditApiRecord
 *         ];
 *     }
 * }
 * ```
 *
 * Records reach forms, tables, infolists and actions as `ApiRecord`s. Labels and the
 * slug come from the class name (`SupplierResource` → "supplier", `/suppliers`).
 * Authorisation: override `canViewAny()`, `canCreate()`, `canEdit()`, `canView()`,
 * `canDelete()`, `canDeleteAny()`, or set `$policy` to a policy class; without either
 * everything is allowed (denied under `strictAuthorization()`). The current tenant is
 * passed to the data source (`DataSource::getTenant()`, `RecordsQuery::$tenant`) instead
 * of scoping a query.
 */
abstract class ApiResource extends Resource
{
    /** @var class-string<DataSource>|null */
    protected static ?string $dataSource = null;

    /**
     * A policy class checked for every ability (`viewAny`, `create`, `update`, `view`,
     * `delete`, `deleteAny`, …), receiving the user and the `ApiRecord`.
     *
     * @var class-string|null
     */
    protected static ?string $policy = null;

    /** The tenant is handed to the data source; there is no query to scope. */
    protected static bool $isScopedToTenant = false;

    // ---------------------------------------------------------------- Data source

    /**
     * A fresh data source for the current request.
     */
    public static function getDataSource(): DataSource
    {
        $class = static::$dataSource ?? throw new LogicException(static::class.' has no $dataSource: set it to a DataSource class or override getDataSource().');

        return app($class)->recordClass(static::getModel());
    }

    /**
     * The table's records for a list page. A failing data source shows an error
     * notification and an empty table instead of an error page.
     *
     * @return LengthAwarePaginator<array-key, ApiRecord>|Paginator<array-key, ApiRecord>|Collection<array-key, ApiRecord>
     */
    public static function getTableRecords(RecordsQuery $query): LengthAwarePaginator|Paginator|Collection
    {
        try {
            return static::getDataSource()->getRecords($query);
        } catch (DataSourceException $exception) {
            $exception->notify();

            return collect();
        }
    }

    /**
     * @return class-string<Model>
     */
    public static function getModel(): string
    {
        return static::$model ?? ApiRecord::class;
    }

    public static function getEloquentQuery(): Builder
    {
        throw new LogicException(static::class.' is an ApiResource: its records come from '.(static::$dataSource ?? 'its data source').', not an Eloquent query. Use the ListApiRecords, CreateApiRecord, EditApiRecord and ViewApiRecord pages.');
    }

    public static function resolveRecordRouteBinding(mixed $key): ?Model
    {
        return is_scalar($key) ? static::getDataSource()->getRecord((string) $key) : null;
    }

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    // ---------------------------------------------------------------- Labels

    /**
     * The name labels and the slug are guessed from: `SupplierResource` → `Supplier`.
     */
    protected static function getRecordName(): string
    {
        return Str::beforeLast(class_basename(static::class), 'Resource') ?: class_basename(static::class);
    }

    public static function getModelLabel(): string
    {
        return static::$modelLabel ?? (static::$model !== null ? parent::getModelLabel() : ModelLabel::singular(static::getRecordName()));
    }

    public static function getPluralModelLabel(): string
    {
        if (static::$pluralModelLabel !== null || static::$modelLabel !== null || static::$model !== null) {
            return parent::getPluralModelLabel();
        }

        return ModelLabel::plural(static::getRecordName());
    }

    public static function getSlug(): string
    {
        return static::$slug ?? Str::slug(Labels::pluralize(ModelLabel::guess(static::$model ?? static::getRecordName()), 'en'));
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if ($record instanceof ApiRecord) {
            $title = static::getDataSource()->getRecordTitle($record->getAttributes());

            if ($title !== null) {
                return $title;
            }
        }

        return parent::getRecordTitle($record);
    }

    // ---------------------------------------------------------------- Authorisation

    /**
     * Every ability check (pages, built-in actions, bulk record checks) goes through the
     * matching `can*()` method, so overriding those is enough.
     */
    public static function can(string $action, ?Model $record = null): bool
    {
        return match (true) {
            $action === 'viewAny' => static::canViewAny(),
            $action === 'create' => static::canCreate(),
            $action === 'deleteAny' => static::canDeleteAny(),
            $action === 'update' && $record !== null => static::canEdit($record),
            $action === 'view' && $record !== null => static::canView($record),
            $action === 'delete' && $record !== null => static::canDelete($record),
            default => static::authorize($action, $record),
        };
    }

    public static function canViewAny(): bool
    {
        return static::authorize('viewAny');
    }

    public static function canCreate(): bool
    {
        return static::authorize('create');
    }

    public static function canEdit(Model $record): bool
    {
        return static::authorize('update', $record);
    }

    public static function canView(Model $record): bool
    {
        return static::authorize('view', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return static::authorize('delete', $record);
    }

    public static function canDeleteAny(): bool
    {
        return static::authorize('deleteAny');
    }

    /**
     * The default check behind the `can*()` methods: `$policy` when set, otherwise a policy
     * registered for the record class, otherwise allowed (denied under strict
     * authorisation).
     */
    protected static function authorize(string $ability, ?Model $record = null): bool
    {
        if (static::$shouldSkipAuthorization) {
            return true;
        }

        if (static::$policy === null) {
            return parent::can($ability, $record);
        }

        $user = alfakit()->getCurrentOrDefaultPanel()->auth()->user();
        $policy = app(static::$policy);

        if ($user === null) {
            return false;
        }

        if (method_exists($policy, 'before')) {
            $before = $policy->before($user, $ability);

            if ($before !== null) {
                return (bool) $before;
            }
        }

        if (! method_exists($policy, $ability)) {
            return ! Authorization::isStrict();
        }

        return (bool) ($record === null ? $policy->{$ability}($user) : $policy->{$ability}($user, $record));
    }

    // ---------------------------------------------------------------- Global search

    /**
     * Offered when the data source implements `search()`.
     */
    public static function canGloballySearch(): bool
    {
        return static::$isGloballySearchable && static::getDataSource()->supportsGlobalSearch() && static::canAccess();
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public static function getGlobalSearchResults(string $search): Collection
    {
        try {
            $records = static::getDataSource()->getSearchResults(trim($search), static::getGlobalSearchResultsLimit());
        } catch (DataSourceException $exception) {
            report($exception->getPrevious() ?? $exception);

            return collect();
        }

        return $records
            ->map(static fn (ApiRecord $record): ?GlobalSearchResult => static::getGlobalSearchResultUrl($record) === null ? null : new GlobalSearchResult(
                title: static::getGlobalSearchResultTitle($record),
                url: (string) static::getGlobalSearchResultUrl($record),
                details: static::getGlobalSearchResultDetails($record),
                actions: static::serializeGlobalSearchResultActions($record),
            ))
            ->filter()
            ->values();
    }
}
