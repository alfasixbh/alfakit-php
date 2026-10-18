<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages;

use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Resources\Pages\Concerns\HasTabs;
use AlfaSix\Alfakit\Schemas\Components\EmbeddedTable;
use AlfaSix\Alfakit\Tables\Table;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ListRecords extends Page
{
    use HasTabs;

    /** `getUrl(['tableAction' => 'edit', 'tableActionRecord' => $id])` opens that action's modal. */
    public const TABLE_ACTION_QUERY_KEY = 'tableAction';

    public const TABLE_ACTION_RECORD_QUERY_KEY = 'tableActionRecord';

    public const TABLE_ACTION_ARGUMENTS_QUERY_KEY = 'tableActionArguments';

    public static function canAccess(): bool
    {
        return static::getResource()::canViewAny();
    }

    public static function getNavigationLabel(): string
    {
        return \AlfaSix\Alfakit\Support\Html::toText(static::getResource()::getNavigationLabel());
    }

    public function getTitle(): string
    {
        return static::$title ?? static::getResource()::getTitleCasePluralModelLabel();
    }

    public function getBreadcrumbs(): array
    {
        return [...$this->getParentBreadcrumbs(), static::getResource()::getUrl() => static::getResource()::getTitleCasePluralModelLabel(), __('alfakit::panel.resources.list')];
    }

    public function table(Table $table): Table
    {
        $resource = static::getResource();

        return $resource::table(
            // The active list tab narrows the query (it is part of the query string, like filters).
            $table->query(fn () => $this->shouldApplyActiveTabQuery() ? $this->modifyQueryWithActiveTab($resource::getEloquentQuery()) : $resource::getEloquentQuery())
                ->modelLabel($resource::getModelLabel())
                ->pluralModelLabel($resource::getPluralModelLabel())
                ->recordUrl(fn ($record): ?string => $this->getTableRecordUrl($record)),
        );
    }

    protected function getTableRecordUrl(\Illuminate\Database\Eloquent\Model $record): ?string
    {
        $resource = static::getResource();

        if ($resource::hasPage('edit') && $resource::canEdit($record)) {
            return $resource::getUrl('edit', ['record' => $record]);
        }

        if ($resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->cachedTabs = null;
    }

    /**
     * Pages with `deferBadge()` tabs count those badges in a deferred request.
     */
    public function render(): InertiaResponse
    {
        $response = parent::render();

        if ($this->hasDeferredTabBadges()) {
            $response->with(self::TAB_BADGES_PROP, Inertia::defer(fn (): array => $this->getDeferredTabBadges(), self::TAB_BADGES_PROP));
        }

        return $response;
    }

    protected function serializeTable(Table $table, string $component = 'table'): array
    {
        return [...parent::serializeTable($table, $component), ...$this->serializeTabs()];
    }

    /**
     * The table, with any `content()` components placed around it: before and after an
     * `EmbeddedTable` marker, or above the table when there is no marker.
     */
    protected function getContentBlocks(): array
    {
        $table = $this->getTable();

        return $this->composeContentBlocks(['table' => $table === null ? null : $this->serializeTable($table)]);
    }

    /**
     * The marker that places the table inside `content()`.
     */
    public function getTableContentComponent(): EmbeddedTable
    {
        return EmbeddedTable::make();
    }

    /**
     * `?tableAction=edit&tableActionRecord=5` (`Resource::getUrl('index', [...])`) opens
     * that record action's modal, `?tableAction=create` a table header action's; otherwise
     * the page's `$defaultAction` / `?defaultAction=`.
     */
    protected function getRequestedDefaultAction(): ?array
    {
        $request = request();
        $name = $request->query(self::TABLE_ACTION_QUERY_KEY);

        if (! is_string($name) || $name === '') {
            return parent::getRequestedDefaultAction();
        }

        $record = $request->query(self::TABLE_ACTION_RECORD_QUERY_KEY);

        return [
            'name' => $name,
            'arguments' => self::queryArray($request->query(self::TABLE_ACTION_ARGUMENTS_QUERY_KEY)),
            // A non-scalar key is rejected by `getDefaultActionSchema()`.
            'context' => ['table' => true, 'recordKey' => $record === '' ? null : $record],
        ];
    }
}
