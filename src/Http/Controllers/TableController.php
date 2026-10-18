<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Tables\Columns\TextColumn;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableQueryString;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TableController
{
    use ResolvesContext;

    /**
     * POST {token, component, record, column, state}: inline cell edit.
     */
    public function updateCell(Request $request): JsonResponse|Response
    {
        $context = $this->resolveContext($request);
        $page = $this->resolvePage($context);
        $table = $this->resolveTable($page, $context->component ?? (string) $request->input('component', 'table'));

        $column = $table->getColumn((string) $request->input('column'));
        // Soft-deleted rows are read-only: inline edits only target live records. Rows of
        // custom `records()` are resolved by key from the records closure.
        $record = $table->getRecord($request->input('record'), withTrashed: false);

        abort_if($column === null || $column->isHidden() || ! method_exists($column, 'updateState'), 404);
        // Text columns have no inline editor; `editable()` ones are edited in spreadsheet mode.
        abort_if($column instanceof TextColumn && ! ($column->isEditable() && $table->isSpreadsheet()), 404);
        abort_if($record === null, 404);
        abort_unless($table->authorizes('update', $record), 403);

        $state = $column->updateState($record, $request->input('state'));

        if ($request->header('X-Inertia')) {
            return back();
        }

        return response()->json(['state' => $state]);
    }

    /**
     * POST {token, component, changes: [{record, column, state, original?}], dryRun?}:
     * spreadsheet mode's batch save. Answers `{saved, errors}` (422 when nothing could be
     * saved because of errors); `dryRun` only validates, e.g. a paste preview.
     */
    public function updateCells(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        // The grid's page query names the same view, tab and filters as the table it edits.
        TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $table = $this->resolveTable($page, $context->component ?? (string) $request->input('component', 'table'));

        $changes = $request->input('changes');
        abort_unless(is_array($changes) && array_is_list($changes), 422);

        $result = $table->saveSpreadsheetChanges($changes, $request->boolean('dryRun'));
        $failed = $result['errors'] !== [] && ($result['saved'] === [] && ! $request->boolean('dryRun'));

        return response()->json($result, $failed ? 422 : 200);
    }

    /**
     * POST {token, component, tableQuery}: the deferred totals of a `deferTotals()` table
     * (`{total, lastPage, summaries}`) for the page's table state.
     */
    public function totals(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        TableQueryString::apply($request, withPagination: true);
        $page = $this->resolvePage($context);
        $table = $this->resolveTable($page, $context->component ?? (string) $request->input('component', 'table'));

        return response()->json($table->getDeferredTotals());
    }

    /**
     * POST {token, component, order[]}: persist drag-and-drop order.
     */
    public function reorder(Request $request): JsonResponse|Response
    {
        $context = $this->resolveContext($request);
        $page = $this->resolvePage($context);
        $table = $this->resolveTable($page, $context->component ?? (string) $request->input('component', 'table'));

        $table->reorderRecords((array) $request->input('order', []));

        return $request->header('X-Inertia') ? back() : response()->json(['ok' => true]);
    }

    /**
     * POST {token, component, columns[]}: store the user's column order in the session.
     * Unknown and hidden column names are dropped; tables without
     * `reorderableColumns()` answer 404.
     */
    public function reorderColumns(Request $request): JsonResponse|Response
    {
        $context = $this->resolveContext($request);
        $page = $this->resolvePage($context);
        $table = $this->resolveTable($page, $context->component ?? (string) $request->input('component', 'table'));

        abort_unless($table->hasReorderableColumns(), 404);

        $columns = $request->input('columns', []);
        $order = $table->persistColumnOrder(is_array($columns) ? $columns : []);

        return $request->header('X-Inertia') ? back() : response()->json(['columns' => $order]);
    }

    protected function resolveTable(Page $page, string $component): Table
    {
        $table = null;

        if ($component === 'table') {
            $table = $page->getTable();
        } elseif (str_starts_with($component, 'table:')) {
            $table = $page->getNamedTable(substr($component, strlen('table:')));
        } elseif (str_starts_with($component, 'relationManager:') && method_exists($page, 'getRelationManager')) {
            $table = $page->getRelationManager(substr($component, strlen('relationManager:')))?->getTable();
        } elseif (str_starts_with($component, 'widget:')) {
            $widget = $page->getWidgetInstance(substr($component, strlen('widget:')));
            $table = $widget !== null && method_exists($widget, 'getTable') ? $widget->getTable() : null;
        }

        abort_if($table === null, 404, "Table [{$component}] not found.");

        return $table;
    }
}
