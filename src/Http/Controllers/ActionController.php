<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\Exceptions\ActionNotResolvableException;
use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Http\Controllers\Concerns\HandlesFieldActions;
use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Tables\Table;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ActionController
{
    use HandlesFieldActions;
    use ResolvesContext;

    /**
     * GET (POST for field actions, which send the form state): everything needed to open
     * the action's modal.
     */
    public function modal(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        // "Select all" bulk modals see the table as the page shows it, like their run.
        \AlfaSix\Alfakit\Tables\TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $component = $context->component ?? $request->input('component');

        if ($this->isFieldActionComponent($component)) {
            return $this->fieldActionModal($page, $component, $request);
        }

        $action = $this->resolveAction($page, $component, (string) $request->input('action'), $request);

        // Shown-but-unauthorised actions (`authorizationTooltip()` / `authorizationNotification()`) never open.
        abort_unless($action->isVisible() && $action->isAuthorized(), 403);

        $this->applyParentData($action, $request);

        return $this->modalResponse($action, $request);
    }

    /**
     * The modal schema, or `{cancelled: true}` (plus the notifications sent) when
     * `mountUsing()` halted or cancelled the action, so the modal doesn't open.
     */
    protected function modalResponse(Action $action, Request $request): JsonResponse
    {
        try {
            return response()->json($action->toModalSchema());
        } catch (Halt|Cancel) {
            return response()->json(['cancelled' => true, 'notifications' => $this->pullFlashedNotifications($request)]);
        }
    }

    /**
     * POST: run an action and redirect back (or to the action's success URL).
     */
    public function execute(Request $request): Response|Responsable
    {
        $context = $this->resolveContext($request);
        // Header and "select all" bulk actions see the table as the page shows it.
        \AlfaSix\Alfakit\Tables\TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $component = $context->component ?? $request->input('component');

        if ($this->isFieldActionComponent($component)) {
            return $this->executeFieldAction($page, $component, $request);
        }

        $action = $this->resolveAction($page, $component, (string) $request->input('action'), $request);

        // Links navigate; running their handler would e.g. save an empty record.
        abort_if($action->isLink(), 400, "The [{$action->getName()}] action opens a URL and cannot be executed.");

        $this->applyParentData($action, $request);

        $password = $request->input(Action::PASSWORD_CONFIRMATION_INPUT);
        $action->providePasswordConfirmation(is_string($password) ? $password : null);

        try {
            $result = $action->execute(
                data: (array) $request->input('data', []),
                arguments: (array) $request->input('arguments', []),
            );
        } catch (Halt) {
            Inertia::flash('alfakitAction', ['name' => $action->getName(), 'status' => 'halted']);

            return back();
        } catch (Cancel) {
            Inertia::flash('alfakitAction', ['name' => $action->getName(), 'status' => 'cancelled']);

            return back();
        }

        if ($result instanceof Response || $result instanceof Responsable) {
            return $result;
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['result' => $result]);
        }

        $replacement = $this->resolveReplacementAction($page, $component, $action, $request);

        Inertia::flash('alfakitAction', array_filter([
            'name' => $action->getName(),
            'status' => 'success',
            'closeModal' => $replacement !== null ? true : $action->shouldCloseModal(),
            'deselectRecords' => $action instanceof BulkAction ? $action->shouldDeselectRecordsAfterCompletion() : null,
            'cancelParentActions' => $action->getParentActionsToCancel(),
            'parentData' => $this->filledParentData($action) ?: null,
            'replaceMountedAction' => $replacement,
        ], static fn (mixed $value): bool => $value !== null));

        $redirect = \AlfaSix\Alfakit\Support\Url::sanitize($action->getSuccessRedirectUrl());

        return $redirect !== null ? redirect()->to($redirect) : back();
    }

    /**
     * A nested action runs while its parents' modals are open: their form state (sent as
     * `mountedData`, outermost first) is applied to them, so the nested action can read it
     * with `$mountedActions` / `getRawData()` / `getValidatedData()`.
     */
    protected function applyParentData(Action $action, Request $request): void
    {
        $data = $request->input('mountedData');

        if (! is_array($data)) {
            return;
        }

        $parents = array_slice($action->getMountedActions(), 0, -1);

        foreach (array_values($parents) as $index => $parent) {
            $state = $data[$index] ?? null;

            if (is_array($state)) {
                $parent->applyRawData($state);
            }
        }
    }

    /**
     * Values nested actions wrote into their parents with `fillData()`, per parent name.
     *
     * @return array<int, array{name: string, data: array<string, mixed>}>
     */
    protected function filledParentData(Action $action): array
    {
        $filled = [];

        foreach (array_slice($action->getMountedActions(), 0, -1) as $parent) {
            if ($parent->getFilledData() !== []) {
                $filled[] = ['name' => $parent->getName(), 'data' => $parent->getFilledData()];
            }
        }

        return $filled;
    }

    /**
     * `replaceMountedAction()`: the sibling action that opens next (same owner, parents and
     * record), if the user may run it.
     *
     * @return array{name: string, arguments: array<string, mixed>|null, hasModal: bool}|null
     */
    protected function resolveReplacementAction(Page $page, ?string $component, Action $action, Request $request): ?array
    {
        $replacement = $action->getReplacementAction();

        if ($replacement === null) {
            return null;
        }

        $path = [...array_map(static fn (Action $parent): string => $parent->getName(), array_slice($action->getMountedActions(), 0, -1)), $replacement['name']];

        try {
            $next = $this->resolveAction($page, $component, implode(Action::PATH_SEPARATOR, $path), $request);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return null;
        }

        if (! $next->isVisible() || ! $next->isAuthorized()) {
            return null;
        }

        $next->arguments($replacement['arguments']);

        return [
            'name' => $replacement['name'],
            'arguments' => $replacement['arguments'] ?: null,
            'hasModal' => $next->hasModal(),
        ];
    }

    protected function resolveAction(Page $page, ?string $component, string $name, Request $request): Action
    {
        abort_if($name === '', 400, 'Missing action name.');

        // User and tenant menu actions belong to the panel, not the page; the page's
        // token still authorises the request.
        // Panel commands (`Panel::commands()`) too: the command palette runs them.
        if ($component === Panel::USER_MENU_COMPONENT || $component === Panel::TENANT_MENU_COMPONENT || $component === Panel::COMMANDS_COMPONENT) {
            return $this->resolveMenuAction($page, $component, $name);
        }

        $isBulk = $request->boolean('bulk') || $request->has('records');

        try {
            $action = $page->resolveAction($component, $name, $isBulk);
        } catch (ActionNotResolvableException $exception) {
            abort(404, $exception->getMessage());
        }

        $table = $this->resolveTable($page, $component);

        // A nested modal action (`parent>child`) shares its root action's record or
        // selection, so that is what the request's record keys are applied to.
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

        return $action;
    }

    protected function resolveMenuAction(Page $page, string $component, string $name): Action
    {
        $panel = $page->getPanel();

        // Menus are only rendered for signed-in users.
        abort_if($panel->auth()->user() === null, 403);

        $path = explode(Action::PATH_SEPARATOR, $name);
        $action = $panel->getMenuAction($component, (string) array_shift($path));

        abort_if($action === null, 404);

        $action->context($page);
        $action = $path === [] ? $action : $action->getNestedModalAction($path);

        abort_if($action === null, 404);

        return $action;
    }

    /**
     * The selection of a bulk action: the posted keys, or every record matching the
     * table's filters and search. Always within the table's own (tenant-scoped) query.
     *
     * @return \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>|null
     */
    protected function selectedRecordsQuery(Table $table, Request $request): ?\Illuminate\Database\Eloquent\Builder
    {
        if ($request->boolean('allRecords')) {
            return $table->getFilteredSortedQuery();
        }

        $query = $table->getQuery();

        if ($query === null) {
            return null;
        }

        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($query->getModel()), true)) {
            $query->withTrashed();
        }

        $keys = array_values(array_filter((array) $request->input('records', []), is_scalar(...)));

        return $query->whereKey($keys);
    }

    /**
     * The selected rows of a table with custom `records()`.
     *
     * @return \Illuminate\Support\Collection<array-key, mixed>
     */
    protected function selectedCustomRecords(Table $table, Request $request): \Illuminate\Support\Collection
    {
        if ($request->boolean('allRecords')) {
            return $table->getAllFilteredRecords();
        }

        return $table->getRecordsByKeys(array_values(array_filter((array) $request->input('records', []), is_scalar(...))));
    }

    protected function resolveTable(Page $page, ?string $component): ?Table
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
