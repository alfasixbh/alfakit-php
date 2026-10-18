<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Actions\Undo\UndoSnapshot;
use AlfaSix\Alfakit\Actions\Undo\UndoStrategy;
use AlfaSix\Alfakit\Actions\Undo\UndoToken;
use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Support\SchemaContext;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Enumerable;

/**
 * `undoable()`: after the action succeeds its toast offers "Undo" for a few seconds.
 *
 * DeleteAction / DeleteBulkAction on soft-deleting models restore the records; EditAction
 * (and the edit page's save) put the changed attributes back, refusing when the record
 * changed since; any other action passes `undoUsing()`. The undo request is authorised
 * like the action (its policy is checked again) and by a short-lived, single-use token
 * bound to the user, panel, tenant and records.
 *
 * ```php
 * DeleteAction::make()->undoable();
 * Action::make('archive')
 *     ->action(fn (Post $record) => $record->update(['archived' => true]))
 *     ->undoable(fn (Post $record) => $record->update(['archived' => false]), seconds: 10);
 * ```
 */
trait CanBeUndone
{
    protected bool|Closure $isUndoable = false;

    protected ?Closure $undoUsing = null;

    protected int $undoSeconds = 8;

    protected ?UndoStrategy $undoStrategy = null;

    protected bool|Closure|null $skipsConfirmationWhenUndoable = null;

    /** @var array<string>|null */
    protected ?array $undoTrackedAttributes = null;

    /** @var array<string, mixed> */
    protected array $undoData = [];

    /** @var array<string, mixed>|null */
    protected ?array $undoCapture = null;

    /** @var array{token: string, seconds: int, url: string|null}|null */
    protected ?array $issuedUndo = null;

    /**
     * Offer "Undo" in the success toast for `$seconds`. Without `$undoUsing`, built-in
     * actions undo themselves (restore soft-deleted records, put changed attributes back).
     */
    public function undoable(?Closure $undoUsing = null, int $seconds = 8, bool|Closure $condition = true): static
    {
        $this->isUndoable = $condition;
        $this->undoSeconds = max(1, $seconds);

        if ($undoUsing !== null) {
            $this->undoUsing = $undoUsing;
        }

        return $this;
    }

    /**
     * What undoing the action does. Receives `$record` / `$records`, `$data` (the action's
     * validated data), `$arguments` and `$undoData` (what the handler stored with `undoData()`).
     */
    public function undoUsing(?Closure $callback): static
    {
        $this->undoUsing = $callback;

        return $this;
    }

    public function undoStrategy(?UndoStrategy $strategy): static
    {
        $this->undoStrategy = $strategy;

        return $this;
    }

    /**
     * Snapshot undo: only these attributes are tracked (default: every attribute but the
     * key and timestamps).
     *
     * @param  array<string>|null  $attributes
     */
    public function undoTrackedAttributes(?array $attributes): static
    {
        $this->undoTrackedAttributes = $attributes;

        return $this;
    }

    /**
     * From the action's handler: keep values `undoUsing()` needs (`$undoData`).
     *
     * @param  array<string, mixed>  $data
     */
    public function undoData(array $data): static
    {
        $this->undoData = [...$this->undoData, ...$data];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUndoData(): array
    {
        return $this->undoData;
    }

    /**
     * Undoable delete actions skip their confirmation modal (default from
     * `alfakit.undo.skip_delete_confirmation`).
     */
    public function skipConfirmationWhenUndoable(bool|Closure $condition = true): static
    {
        $this->skipsConfirmationWhenUndoable = $condition;

        return $this;
    }

    public function skipsConfirmationWhenUndoable(): bool
    {
        $condition = $this->evaluate($this->skipsConfirmationWhenUndoable);

        return $condition === null ? (bool) config('alfakit.undo.skip_delete_confirmation', true) : (bool) $condition;
    }

    public function getUndoSeconds(): int
    {
        return $this->undoSeconds;
    }

    public function getUndoUsing(): ?Closure
    {
        return $this->undoUsing;
    }

    public function isUndoable(): bool
    {
        return (bool) $this->evaluate($this->isUndoable) && $this->resolveUndoStrategy() !== null;
    }

    public function resolveUndoStrategy(): ?UndoStrategy
    {
        if ($this->undoUsing !== null) {
            return UndoStrategy::Custom;
        }

        return $this->undoStrategy ?? $this->getDefaultUndoStrategy();
    }

    /**
     * Built-in actions name how they undo themselves.
     */
    protected function getDefaultUndoStrategy(): ?UndoStrategy
    {
        return null;
    }

    /**
     * The ability the action was authorised with, checked again on undo (built-in strategies).
     */
    protected function getUndoAbility(): ?string
    {
        return null;
    }

    /**
     * The token of the last successful run, sent with its toast.
     *
     * @return array{token: string, seconds: int, url: string|null}|null
     */
    public function getIssuedUndo(): ?array
    {
        return $this->issuedUndo;
    }

    /**
     * Before the handler: remember what undoing will need (live records, attribute values).
     */
    protected function prepareUndo(): void
    {
        $this->undoCapture = null;
        $this->issuedUndo = null;
        $this->undoData = [];

        if (! $this->isUndoable()) {
            return;
        }

        $strategy = $this->resolveUndoStrategy();

        $this->undoCapture = match ($strategy) {
            UndoStrategy::Restore => ($keys = $this->getUndoableLiveKeys()) === null ? null : ['keys' => $keys],
            UndoStrategy::Snapshot => ($record = $this->getUndoSnapshotRecord()) === null ? null : ['record' => $record, 'before' => UndoSnapshot::read($record)],
            default => [],
        };
    }

    /**
     * After the handler: issue the token and put "Undo" in the toast the action sent (or
     * a generic one).
     */
    protected function issueUndo(int $notificationsBefore): void
    {
        if ($this->undoCapture === null) {
            return;
        }

        $payload = $this->buildUndoPayload($this->undoCapture);
        $this->undoCapture = null;

        if ($payload === null) {
            return;
        }

        $panel = alfakit()->getCurrentOrDefaultPanel();
        $tenant = alfakit()->getTenant();
        $tenantKey = $tenant === null ? null : (string) $tenant->getKey();
        $user = SchemaContext::currentUser();

        $this->issuedUndo = [
            'token' => UndoToken::issue($payload, $this->undoSeconds, $panel->getId(), $user, $tenantKey),
            'seconds' => $this->undoSeconds,
            'url' => $panel->getEndpoints()['undo'] ?? null,
        ];

        if (! Notification::attachUndoToSent($this->issuedUndo, $notificationsBefore)) {
            Notification::make()
                ->success()
                ->title(__('alfakit::actions.undo.done'))
                ->undo($this->issuedUndo)
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $capture
     * @return array<string, mixed>|null
     */
    protected function buildUndoPayload(array $capture): ?array
    {
        $strategy = $this->resolveUndoStrategy();
        $request = request();
        $context = $request->input('token');
        $resource = $this->getContext() instanceof HasResource ? $this->getContext()->getResourceClass() : null;

        $base = [
            'strategy' => $strategy?->value,
            'context' => is_string($context) ? $context : null,
            'component' => is_string($component = $request->input('component')) ? $component : null,
            'action' => is_string($name = $request->input('action')) ? $name : $this->getName(),
            'bulk' => $this instanceof \AlfaSix\Alfakit\Actions\BulkAction,
            'resource' => $resource,
            'ability' => $this->getUndoAbility(),
        ];

        switch ($strategy) {
            case UndoStrategy::Restore:
                $model = $this->getUndoModelClass();

                if ($model === null || $capture['keys'] === []) {
                    return null;
                }

                $instance = new $model;
                $deletedAt = method_exists($instance, 'getQualifiedDeletedAtColumn') ? $instance->getQualifiedDeletedAtColumn() : $instance->qualifyColumn('deleted_at');
                // Only the records this run deleted (not ones that were trashed already).
                $keys = $model::query()->withoutGlobalScopes()->whereKey($capture['keys'])->whereNotNull($deletedAt)->pluck($instance->getKeyName())->all();

                return $keys === [] ? null : [...$base, 'model' => $model, 'keys' => array_values($keys)];

            case UndoStrategy::Snapshot:
                /** @var Model $record */
                $record = $capture['record'];
                $changes = UndoSnapshot::diff($record, $capture['before'], $this->undoTrackedAttributes);

                return $changes === null ? null : [
                    ...$base,
                    'model' => $record::class,
                    'keys' => [$record->getKey()],
                    'before' => $changes['before'],
                    'after' => $changes['after'],
                ];

            case UndoStrategy::Custom:
                $record = $this->getRecord();

                return [
                    ...$base,
                    'model' => $record instanceof Model ? $record::class : $this->getUndoModelClass(),
                    'record' => $request->input('record') ?? ($record instanceof Model ? $record->getKey() : null),
                    'keys' => array_values(array_filter((array) $request->input('records', []), is_scalar(...))),
                    'allRecords' => $request->boolean('allRecords') ?: null,
                    'data' => $this->getUndoActionData(),
                    'arguments' => $this->getArguments(),
                    'undoData' => $this->undoData,
                ];

            default:
                return null;
        }
    }

    /**
     * The validated data of the run, given to `undoUsing()` as `$data` (scalars and arrays).
     *
     * @return array<string, mixed>
     */
    protected function getUndoActionData(): array
    {
        $state = $this->form?->getRawState() ?? [];

        return array_filter($state, static fn (mixed $value): bool => is_scalar($value) || is_array($value) || $value === null);
    }

    /**
     * Restore strategy: keys of the records about to be deleted that aren't trashed yet.
     *
     * @return array<int, mixed>|null
     */
    protected function getUndoableLiveKeys(): ?array
    {
        $model = $this->getUndoModelClass();

        if ($model === null || ! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            return null;
        }

        $record = $this->getRecord();

        if ($record instanceof Model) {
            return $this->isTrashedForUndo($record) ? [] : [$record->getKey()];
        }

        if (! method_exists($this, 'getRecords')) {
            return null;
        }

        $records = $this->getRecords();
        $keys = [];
        $plain = [];

        foreach ($records instanceof Enumerable ? $records : [] as $item) {
            if ($item instanceof Model) {
                if (! $this->isTrashedForUndo($item)) {
                    $keys[] = $item->getKey();
                }
            } elseif (is_scalar($item)) {
                $plain[] = $item;
            }
        }

        if ($plain !== []) {
            // `fetchSelectedRecords(false)`: keys only.
            $instance = new $model;
            $keys = [...$keys, ...$model::query()->whereKey($plain)->pluck($instance->getKeyName())->all()];
        }

        return $keys;
    }

    protected function isTrashedForUndo(Model $record): bool
    {
        return method_exists($record, 'trashed') && (bool) $record->trashed();
    }

    /**
     * @return class-string<Model>|null
     */
    protected function getUndoModelClass(): ?string
    {
        $query = property_exists($this, 'selectedRecordsQuery') ? $this->selectedRecordsQuery : null;

        if ($query instanceof Builder) {
            return $query->getModel()::class;
        }

        $model = $this->getModel();

        return is_string($model) && is_a($model, Model::class, true) ? $model : null;
    }

    /**
     * Snapshot strategy: the action's record, or the record of the page it runs on.
     */
    protected function getUndoSnapshotRecord(): ?Model
    {
        $record = $this->getRecord();

        if ($record instanceof Model) {
            return $record->exists ? $record : null;
        }

        $context = $this->getContext();
        $record = is_object($context) && method_exists($context, 'getRecord') ? $context->getRecord() : null;

        return $record instanceof Model && $record->exists ? $record : null;
    }

    /**
     * Custom strategy: run `undoUsing()` for the records the action ran on.
     *
     * @param  array<string, mixed>  $payload
     */
    public function runUndo(array $payload): void
    {
        if ($this->undoUsing === null) {
            return;
        }

        $this->evaluate($this->undoUsing, [
            'data' => $payload['data'] ?? [],
            'arguments' => $payload['arguments'] ?? [],
            'undoData' => $payload['undoData'] ?? [],
        ]);
    }
}
