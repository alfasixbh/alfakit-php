<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Http\Controllers\Concerns\ResolvesContext;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Tables\TableQueryString;
use AlfaSix\Alfakit\Tables\Views\SavedTableView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * `POST /_alfakit/table/views` {token, component, tableQuery, operation, …}: saved views of
 * a `savedViews()` table.
 *
 * - `create` {name, shared?}: save the table's current state (read from `tableQuery`, the
 *   page's query string, like every internal table request) as a new view.
 * - `update` {target}: overwrite a view with the current state.
 * - `rename` {target, name}, `delete` {target}, `share` {target, shared}.
 * - `default` {target|null, none?}: the user's own default view (`none` for "no view",
 *   no target to forget it).
 *
 * The page is rebuilt from the context token (user, panel access, tenant, `canAccess()`),
 * so views are always scoped to the token's panel, tenant and user. Personal views can only
 * be changed by their owner; shared views by users who manage shared views.
 */
class TableViewsController extends TableController
{
    use ResolvesContext;

    public function handle(Request $request): JsonResponse
    {
        $context = $this->resolveContext($request);
        TableQueryString::apply($request);
        $page = $this->resolvePage($context);
        $table = $this->resolveTable($page, $context->component ?? (string) $request->input('component', 'table'));

        abort_unless($table->hasTableViews(), 404);

        $operation = (string) $request->input('operation');
        $user = $table->getTableViewsUser();

        if ($operation === 'default') {
            abort_unless($table->hasSavedViews() && $user !== null, 403);

            // `none`: "no view" as the default (an empty string would arrive as null).
            $view = $request->boolean('none') ? '' : $request->input('target');
            abort_unless($view === null || is_string($view), 422);
            abort_if(is_string($view) && $view !== '' && $table->getTableViewState($view) === null, 404);

            $table->setUserDefaultTableView($view);

            return $this->respond($table, null);
        }

        abort_unless($table->canSaveTableViews() && $user !== null, 403);

        if ($operation === 'create') {
            $name = $this->validName($request);
            $shared = $request->boolean('shared');

            abort_if($shared && ! $table->canManageSharedTableViews(), 403);

            $owner = SavedTableView::owner($user);
            $panel = alfakit()->getCurrentOrDefaultPanel()->getId();
            $scope = ['panel' => $panel, 'table_key' => $table->getTableViewsKey(), 'tenant_key' => $table->getTableViewsTenantKey() ?? ''];

            if (SavedTableView::query()->where($scope)->where($owner)->count() >= Table::MAX_SAVED_VIEWS) {
                throw ValidationException::withMessages(['name' => __('alfakit::tables.views.too_many', ['max' => Table::MAX_SAVED_VIEWS])]);
            }

            $view = SavedTableView::query()->create([
                ...$scope,
                ...$owner,
                'slug' => $this->uniqueSlug($scope, $name, array_keys($table->getPresetViews())),
                'name' => $name,
                'is_shared' => $shared,
                'state' => $table->captureTableViewState(),
            ]);

            $table->flushTableViews();

            return $this->respond($table, $view->slug);
        }

        $slug = $request->input('target');
        abort_unless(is_string($slug) && $slug !== '', 422);

        // Code views are read-only; other users' personal views are not even visible.
        abort_if(array_key_exists($slug, $table->getPresetViews()), 403);

        $view = $table->findUserTableView($slug);
        abort_if($view === null, 404);
        abort_unless($table->canEditTableView($view), 403);

        switch ($operation) {
            case 'update':
                $view->update(['state' => $table->captureTableViewState()]);
                break;

            case 'rename':
                $view->update(['name' => $this->validName($request)]);
                break;

            case 'share':
                abort_unless($table->canManageSharedTableViews(), 403);
                $view->update(['is_shared' => $request->boolean('shared')]);
                break;

            case 'delete':
                $view->delete();
                $table->flushTableViews();

                return $this->respond($table, null, deleted: $slug);

            default:
                abort(422);
        }

        $table->flushTableViews();

        return $this->respond($table, $view->slug);
    }

    protected function validName(Request $request): string
    {
        $name = $request->input('name');
        $name = is_string($name) ? trim($name) : '';

        if ($name === '' || mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => __('alfakit::tables.views.invalid_name')]);
        }

        return $name;
    }

    /**
     * @param  array<string, string>  $scope
     * @param  array<string>  $reserved  Keys of the code views.
     */
    protected function uniqueSlug(array $scope, string $name, array $reserved): string
    {
        $base = Str::limit(Str::slug($name), 100, '') ?: 'view';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (in_array($slug, $reserved, true) || SavedTableView::query()->where($scope)->where('slug', $slug)->exists());

        return $slug;
    }

    protected function respond(Table $table, ?string $active, ?string $deleted = null): JsonResponse
    {
        $views = $table->getTableViewsSchema();

        return response()->json(array_filter([
            'views' => $views,
            'view' => $active,
            'deleted' => $deleted,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
