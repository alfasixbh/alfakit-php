<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Binds an action to what the request targets: the record of a table action, or the
 * selection of a bulk action. Used by the schema endpoints (live refresh and select
 * options of a bulk action's modal form and its nested actions) and mirroring
 * `ActionController::resolveAction()`, so a bulk action's form sees the same `$records`
 * when it refreshes as when it runs.
 */
trait ResolvesActionSelection
{
    /**
     * Whether the request addresses a bulk action (`bulk=1`, or selected `records`).
     */
    protected function isBulkActionRequest(Request $request): bool
    {
        return $request->boolean('bulk') || $request->has('records');
    }

    /**
     * Give the action's root (`parent>child` nested modal actions share it) the request's
     * record or selection. Nothing happens for page actions.
     */
    protected function applyActionTarget(Action $action, Page $page, ?string $component, Request $request): void
    {
        $table = $this->resolveActionTable($page, $component);
        $target = $action->getRootAction();

        if ($target instanceof BulkAction) {
            abort_if($table === null, 400);

            if ($table->hasCustomRecords()) {
                // Rows of custom `records()` have no query: they are resolved by key from
                // the records closure (or every filtered row for "select all").
                $target->records(
                    $this->selectedCustomRecords($table, $request)->filter(static fn (mixed $record): bool => $table->isRecordSelectable($record))->values(),
                );
            } else {
                $target->selectedRecordsQuery(
                    $this->selectedRecordsQuery($table, $request),
                    static fn ($record): bool => $table->isRecordSelectable($record),
                );
            }
        } elseif ($table !== null && $request->filled('record')) {
            $record = $table->getRecord($request->input('record'));

            abort_if($record === null, 404);

            $target->record($record)->recordKey($request->input('record'));
        }
    }

    /**
     * The selection of a bulk action: the posted keys, or every record matching the
     * table's filters and search. Always within the table's own (tenant-scoped) query.
     *
     * @return Builder<Model>|null
     */
    protected function selectedRecordsQuery(Table $table, Request $request): ?Builder
    {
        if ($request->boolean('allRecords')) {
            return $table->getFilteredSortedQuery();
        }

        $query = $table->getQuery();

        if ($query === null) {
            return null;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withTrashed();
        }

        $keys = array_values(array_filter((array) $request->input('records', []), is_scalar(...)));

        return $query->whereKey($keys);
    }

    /**
     * The selected rows of a table with custom `records()`.
     *
     * @return Collection<array-key, mixed>
     */
    protected function selectedCustomRecords(Table $table, Request $request): Collection
    {
        if ($request->boolean('allRecords')) {
            return $table->getAllFilteredRecords();
        }

        return $table->getRecordsByKeys(array_values(array_filter((array) $request->input('records', []), is_scalar(...))));
    }

    protected function resolveActionTable(Page $page, ?string $component): ?Table
    {
        if ($component === 'table') {
            return $page->getTable();
        }

        if ($component !== null && str_starts_with($component, 'table:')) {
            return $page->getNamedTable(substr($component, strlen('table:')));
        }

        if ($component !== null && str_starts_with($component, 'relationManager:') && method_exists($page, 'getRelationManager')) {
            $manager = $page->getRelationManager(substr($component, strlen('relationManager:')));

            return $manager instanceof RelationManager ? $manager->getTable() : null;
        }

        if ($component !== null && str_starts_with($component, 'widget:')) {
            $widget = $page->getWidgetInstance(substr($component, strlen('widget:')));

            return $widget !== null && method_exists($widget, 'getTable') ? $widget->getTable() : null;
        }

        return null;
    }
}
