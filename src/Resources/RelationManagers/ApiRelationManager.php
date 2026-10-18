<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\RelationManagers;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Actions\EditAction;
use AlfaSix\Alfakit\Actions\ViewAction;
use AlfaSix\Alfakit\Resources\Api\ApiRecord;
use AlfaSix\Alfakit\Resources\Api\DataSource;
use AlfaSix\Alfakit\Resources\Api\DataSourceException;
use AlfaSix\Alfakit\Resources\Api\RecordsQuery;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\ModelLabel;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use LogicException;

/**
 * A relation-like section on the edit and view pages of a resource (an `ApiResource` or
 * an Eloquent one) whose records come from a data source instead of a relationship:
 * a supplier's open purchase orders from the ERP, a customer's tickets from the help
 * desk. The data source sees the page's record as `getOwnerRecord()` (and
 * `RecordsQuery::$owner`).
 *
 * ```php
 * class PurchaseOrdersRelationManager extends ApiRelationManager
 * {
 *     protected static string $dataSource = PurchaseOrderSource::class;
 *
 *     public function table(Table $table): Table
 *     {
 *         return $table->columns([...])->recordActions([EditAction::make(), DeleteAction::make()]);
 *     }
 * }
 * ```
 *
 * Create, edit and view actions open the manager's `form()` in a modal and save through
 * the data source. Like any relation manager it is read-only on view pages and when the
 * user may not edit the owner record.
 */
abstract class ApiRelationManager extends RelationManager
{
    protected static string $relationship = '';

    /** @var class-string<DataSource> */
    protected static string $dataSource;

    /** @var class-string<ApiRecord> */
    protected static string $recordClass = ApiRecord::class;

    public function getDataSource(): DataSource
    {
        return app(static::$dataSource)->recordClass(static::$recordClass)->forOwner($this->ownerRecord);
    }

    public function getRelationship(): Relation
    {
        throw new LogicException(static::class.' reads its records from '.static::$dataSource.', not a relationship.');
    }

    public static function getRelationshipName(): string
    {
        return static::$relationship !== '' ? static::$relationship : Str::camel(Str::beforeLast(class_basename(static::class), 'RelationManager'));
    }

    public static function getTitle(): string
    {
        return static::$title ?? Labels::fromName(static::getRelationshipName());
    }

    public function getModelLabel(): string
    {
        return static::$modelLabel ?? ModelLabel::singular(Str::singular(Str::studly(static::getRelationshipName())));
    }

    public function getPluralModelLabel(): string
    {
        return static::$pluralModelLabel
            ?? (static::$modelLabel !== null ? ModelLabel::pluralizeOverride(static::$modelLabel) : ModelLabel::plural(Str::singular(Str::studly(static::getRelationshipName()))));
    }

    public function getTable(): Table
    {
        if ($this->cachedTable !== null) {
            return $this->cachedTable;
        }

        $identifier = $this->getTableQueryStringIdentifier();

        $table = Table::make($this)
            ->queryStringIdentifier($identifier)
            ->records(fn (?string $search, array $columnSearches, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage, array $filters): mixed => $this->getTableRecords(new RecordsQuery(
                search: $search,
                columnSearches: $columnSearches,
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
                filters: $filters,
                page: $page,
                perPage: is_numeric($recordsPerPage) ? (int) $recordsPerPage : null,
                tenant: $this->getDataSource()->getTenant(),
                owner: $this->ownerRecord,
            )))
            ->resolveRecordUsing(function (string $key): ?Model {
                try {
                    return $this->getDataSource()->getRecord($key);
                } catch (DataSourceException $exception) {
                    $exception->notify();

                    return null;
                }
            })
            // Actions take the table's model: create actions make an `ApiRecord`.
            ->model(static::$recordClass)
            ->modelLabel($this->getModelLabel())
            ->pluralModelLabel($this->getPluralModelLabel())
            ->heading($this->getTableHeading());

        $table = $this->table($table);

        foreach ([...$table->getRecordActions(), ...$table->getHeaderActions(), ...$table->getBulkActions(), ...$table->getEmptyStateActions()] as $action) {
            $this->configureAction($action);
        }

        $table->state(TableState::fromRequest(request(), $identifier));

        return $this->cachedTable = $table;
    }

    /**
     * @return iterable<mixed>
     */
    protected function getTableRecords(RecordsQuery $query): iterable
    {
        try {
            return $this->getDataSource()->getRecords($query);
        } catch (DataSourceException $exception) {
            $exception->notify();

            return [];
        }
    }

    protected function configureAction(Action|ActionGroup $action): void
    {
        if ($action instanceof ActionGroup) {
            foreach ($action->getFlatActions() as $nested) {
                $this->configureAction($nested);
            }

            return;
        }

        $action->context($this);
        $action->model(static::$recordClass);

        if ($this->isReadOnly() && $this->isWriteAction($action)) {
            $action->hidden();
        }

        $action->recordTitle(fn (?Model $record): ?string => $this->getRecordTitle($record));

        if ($action instanceof CreateAction && ! $action->hasForm()) {
            $action->form(fn (): array => $this->form(Schema::make())->getComponents(withHidden: true))
                ->using(function (array $data): Model {
                    $record = $this->getDataSource()->makeRecord(exists: false);
                    $record->persist($data);

                    return $record;
                });
        }

        if (($action instanceof EditAction || $action instanceof ViewAction) && ! $action->hasForm()) {
            $action->form(fn (): array => $this->form(Schema::make())->getComponents(withHidden: true));
        }
    }

    public function getRecordTitle(?Model $record): ?string
    {
        if ($record instanceof ApiRecord && static::getRecordTitleAttribute() === null) {
            $title = $this->getDataSource()->getRecordTitle($record->getAttributes());

            if ($title !== null) {
                return $title;
            }
        }

        return parent::getRecordTitle($record);
    }
}
