<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\Undo\UndoConflict;
use AlfaSix\Alfakit\Actions\Undo\UndoSnapshot;
use AlfaSix\Alfakit\Actions\Undo\UndoStrategy;
use AlfaSix\Alfakit\Actions\Undo\UndoToken;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\SchemaContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * `POST /_alfakit/actions/undo` {undo}: undo an `undoable()` action from its toast.
 *
 * The undo token is encrypted, expires with the toast's countdown and works once (its
 * payload is pulled from the cache). It names the user, panel and tenant it was issued
 * for, and carries the context token of the page the action ran on, which is checked like
 * any internal request (user, panel access, auth middleware, tenant, `canAccess()`). The
 * action's policy is checked again: built-in undos re-check the ability on every record,
 * custom ones rebuild the page and the action and check `isAuthorized()`.
 */
class UndoController extends ActionController
{
    public function undo(Request $request): RedirectResponse|JsonResponse
    {
        $token = $request->input('undo');
        abort_if(! is_string($token) || $token === '', 400, 'Missing undo token.');

        $claims = UndoToken::decode($token);
        $panel = alfakit()->getCurrentPanel();

        abort_unless($panel !== null && $claims->claims['panel'] === $panel->getId(), 403);
        abort_unless($claims->claims['user'] !== null && $claims->claims['user'] === SchemaContext::currentUser(), 403);

        if ($claims->isExpired() || ($payload = $claims->redeem()) === null) {
            return $this->fail($request, __('alfakit::actions.undo.expired'));
        }

        $context = is_string($payload['context'] ?? null) ? SchemaContext::fromToken($payload['context']) : null;

        abort_if($context === null, 403);
        abort_unless($context->panel === $panel->getId() && $context->user === SchemaContext::currentUser(), 403);

        $this->authorizePageContext($context);

        $tenant = alfakit()->getTenant();
        abort_unless(($tenant === null ? null : (string) $tenant->getKey()) === $claims->claims['tenant'], 403);

        $strategy = UndoStrategy::tryFrom((string) ($payload['strategy'] ?? ''));

        try {
            match ($strategy) {
                UndoStrategy::Restore => $this->restore($payload),
                UndoStrategy::Snapshot => $this->restoreSnapshot($payload, $context),
                UndoStrategy::Custom => $this->runCustom($request, $context, $payload),
                default => abort(400),
            };
        } catch (UndoConflict $conflict) {
            return $this->fail($request, $conflict->getMessage());
        }

        Notification::make()->success()->title(__('alfakit::actions.undo.notification'))->send();

        return $this->respond($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function restore(array $payload): void
    {
        $records = $this->records($payload);

        abort_if($records->isEmpty(), 404);

        $this->authorizeRecords($payload, $records, 'delete');

        DB::transaction(static function () use ($records): void {
            foreach ($records as $record) {
                if (method_exists($record, 'restore')) {
                    $record->restore();
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function restoreSnapshot(array $payload, SchemaContext $context): void
    {
        $records = $this->records($payload);
        $record = $records->first();

        abort_if($record === null, 404);

        $this->authorizeRecords($payload, $records, 'update');

        $after = is_array($payload['after'] ?? null) ? $payload['after'] : [];
        $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];

        // Someone saved the record again since: putting the old values back would lose that.
        if (UndoSnapshot::conflicts($record, $after) !== []) {
            throw new UndoConflict(__('alfakit::actions.undo.conflict'));
        }

        UndoSnapshot::restore($record, $before);

        $this->refreshPageForm($context, $record, array_keys($before));
    }

    /**
     * The undo ran on the record's own Edit / View page (the edit page's save): its form
     * shows the restored values instead of the undone ones (`refreshFormData()`).
     *
     * @param  array<int|string, mixed>  $attributes
     */
    protected function refreshPageForm(SchemaContext $context, Model $record, array $attributes): void
    {
        if (! is_string($context->page) || ! (is_a($context->page, EditRecord::class, true) || is_a($context->page, ViewRecord::class, true))) {
            return;
        }

        $page = $this->rebuildPage($context);

        if (($page instanceof EditRecord || $page instanceof ViewRecord) && $page->getRecord()?->is($record) === true) {
            $page->refreshFormData(array_values(array_filter($attributes, is_string(...))));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function runCustom(Request $request, SchemaContext $context, array $payload): void
    {
        $page = $this->rebuildPage($context);
        $component = is_string($payload['component'] ?? null) ? $payload['component'] : $context->component;

        // The action is resolved exactly as when it ran: same component, record or selection.
        $request->merge(array_filter([
            'record' => $payload['record'] ?? null,
            'records' => ($payload['keys'] ?? []) ?: null,
            'allRecords' => $payload['allRecords'] ?? null,
            'bulk' => ($payload['bulk'] ?? false) ?: null,
        ], static fn (mixed $value): bool => $value !== null));

        $action = $this->resolveAction($page, $component, (string) ($payload['action'] ?? ''), $request);

        // Authorisation is checked against the record as it is now: an action whose ability
        // depends on what it changed may no longer be allowed. Say so instead of a raw 403.
        if (! $action->isAuthorized() || $action->getUndoUsing() === null) {
            throw new UndoConflict(__('alfakit::actions.undo.expired'));
        }

        DB::transaction(static fn () => $action->runUndo($payload));
    }

    protected function rebuildPage(SchemaContext $context): Page
    {
        /** @var class-string<Page> $class */
        $class = $context->page;

        try {
            return $class::fromContext($context);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            throw new UndoConflict(__('alfakit::actions.undo.unavailable'));
        } catch (HttpException $exception) {
            throw $exception->getStatusCode() === 404 ? new UndoConflict(__('alfakit::actions.undo.unavailable')) : $exception;
        }
    }

    /**
     * The records the action ran on (trashed ones too), by key.
     *
     * @param  array<string, mixed>  $payload
     * @return Collection<int, Model>
     */
    protected function records(array $payload): Collection
    {
        $model = $payload['model'] ?? null;
        $keys = array_values(array_filter((array) ($payload['keys'] ?? []), is_scalar(...)));

        abort_unless(is_string($model) && is_a($model, Model::class, true) && $keys !== [], 400);

        // Deleted records are found too (the restore strategy is about them).
        $query = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)
            ? $model::query()->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            : $model::query();

        return $query->whereKey($keys)->get()->values();
    }

    /**
     * The ability the action was authorised with, on every record (the resource's policy
     * when the action ran on a resource).
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, Model>  $records
     */
    protected function authorizeRecords(array $payload, Collection $records, string $default): void
    {
        $ability = is_string($payload['ability'] ?? null) ? $payload['ability'] : $default;
        $resource = $payload['resource'] ?? null;

        foreach ($records as $record) {
            $allowed = is_string($resource) && is_a($resource, Resource::class, true)
                ? $resource::authorizes($ability, $record)
                : Authorization::allows($ability, $record);

            abort_unless($allowed, 403);
        }
    }

    protected function fail(Request $request, string $message): RedirectResponse|JsonResponse
    {
        Notification::make()->danger()->title($message)->send();

        return $this->respond($request, 409);
    }

    protected function respond(Request $request, int $status = 200): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['notifications' => $this->pullFlashedNotifications($request)], $status);
        }

        return back();
    }

    /**
     * Action resolution may 404 for a record that is gone; that is reported like a refusal.
     */
    protected function resolveAction(Page $page, ?string $component, string $name, Request $request): Action
    {
        try {
            return parent::resolveAction($page, $component, $name, $request);
        } catch (HttpException $exception) {
            throw $exception->getStatusCode() === 404 ? new UndoConflict(__('alfakit::actions.undo.unavailable')) : $exception;
        }
    }
}
