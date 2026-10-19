<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Actions\BulkAction;
use AlfaSix\Alfakit\Actions\Exceptions\Cancel;
use AlfaSix\Alfakit\Contracts\HasResource;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Support\Authorization;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;

/**
 * How a bulk action gets its records: the table hands it a query for the selection (the
 * selected keys, or every filtered record), and the records are loaded from it at once or,
 * with `chunkSelectedRecords()`, lazily in chunks. `authorizeIndividualRecords()` drops
 * the records the user may not act on and tells them how many were skipped.
 *
 * The table the selection comes from (`selectionTable()`, from `CanAccessSelectedRecords`)
 * enforces its `maxSelectableRecords()` and `selectGroupsOnly()`.
 *
 * `fetchSelectedRecords(false)` hands the action the selected keys instead of models.
 * `reportBulkProcessingFailure()` counts records the action could not process; the user
 * is then told how many failed (`failureNotificationTitle()` receives `$successCount`,
 * `$failureCount` and `$totalCount`).
 */
trait InteractsWithSelectedRecords
{
    /** @var Builder<Model>|null */
    protected ?Builder $selectedRecordsQuery = null;

    protected ?Closure $selectedRecordsFilter = null;

    protected int|Closure|null $selectedRecordsChunkSize = null;

    protected string|Closure|null $individualRecordAuthorization = null;

    /** @var Collection<int, Model>|LazyCollection<int, Model>|SupportCollection<int, mixed>|null */
    protected SupportCollection|LazyCollection|null $resolvedRecords = null;

    protected int $unauthorizedRecordsCount = 0;

    protected string|Closure|null $unauthorizedRecordsNotificationTitle = null;

    protected bool|Closure $shouldFetchSelectedRecords = true;

    /** @var array<string, array{count: int, message: string|Closure|null}> */
    protected array $bulkProcessingFailures = [];

    protected bool $hasSentBulkProcessingNotification = false;

    /**
     * `false` gives the action the selected keys (`Illuminate\Support\Collection $records`)
     * without loading the models, for very large selections.
     */
    public function fetchSelectedRecords(bool|Closure $condition = true): static
    {
        $this->shouldFetchSelectedRecords = $condition;
        $this->resolvedRecords = null;

        return $this;
    }

    public function shouldFetchSelectedRecords(): bool
    {
        return (bool) $this->evaluate($this->shouldFetchSelectedRecords);
    }

    /**
     * Report that one record could not be processed. Failures with the same `$key` share
     * one `$message` (`fn (int $failureCount, int $totalCount): string`) in the
     * notification. Call it at most once per record.
     */
    public function reportBulkProcessingFailure(string $key = 'default', string|Closure|null $message = null): static
    {
        $this->bulkProcessingFailures[$key] = [
            'count' => ($this->bulkProcessingFailures[$key]['count'] ?? 0) + 1,
            'message' => $message ?? ($this->bulkProcessingFailures[$key]['message'] ?? null),
        ];

        return $this;
    }

    public function getBulkProcessingFailureCount(): int
    {
        return array_sum(array_column($this->bulkProcessingFailures, 'count'));
    }

    /**
     * How many records the action went through (the selection after authorisation).
     */
    public function getBulkProcessingTotalCount(): int
    {
        $records = $this->resolvedRecords ?? $this->getRecords();

        return $records instanceof LazyCollection ? $records->count() : count($records);
    }

    public function getBulkProcessingSuccessCount(): int
    {
        return max(0, $this->getBulkProcessingTotalCount() - $this->getBulkProcessingFailureCount());
    }

    /**
     * The selection as a query (set by the action endpoint). `$filter` drops records that
     * can't be selected.
     *
     * @param  Builder<Model>|null  $query
     */
    public function selectedRecordsQuery(?Builder $query, ?Closure $filter = null): static
    {
        $this->selectedRecordsQuery = $query;
        $this->selectedRecordsFilter = $filter;
        $this->resolvedRecords = null;

        return $this;
    }

    /**
     * Load the selected records lazily, `$size` at a time, so an action over thousands of
     * records doesn't hold them all in memory. `$records` is then a `LazyCollection`.
     */
    public function chunkSelectedRecords(int|Closure|null $size = 100): static
    {
        $this->selectedRecordsChunkSize = $size;
        $this->resolvedRecords = null;

        return $this;
    }

    public function getSelectedRecordsChunkSize(): ?int
    {
        $size = $this->evaluate($this->selectedRecordsChunkSize);

        return $size === null ? null : max(1, (int) $size);
    }

    /**
     * Check each selected record: a policy ability (`'delete'`) or a closure receiving
     * `$record`. Records that fail are left out of `$records`.
     */
    public function authorizeIndividualRecords(string|Closure|null $ability = 'update'): static
    {
        $this->individualRecordAuthorization = $ability;
        $this->resolvedRecords = null;

        return $this;
    }

    public function unauthorizedRecordsNotificationTitle(string|Closure|null $title): static
    {
        $this->unauthorizedRecordsNotificationTitle = $title;

        return $this;
    }

    /**
     * @param  Model|array<mixed>  $record  A model, or an array row of custom `records()`.
     */
    public function isRecordAuthorized(Model|array $record): bool
    {
        $ability = $this->individualRecordAuthorization;

        if ($ability === null) {
            return true;
        }

        if ($ability instanceof Closure) {
            return (bool) $this->evaluate($ability, ['record' => $record], $record instanceof Model ? [Model::class => $record, $record::class => $record] : []);
        }

        // Array rows have no policy; only a closure can authorise them individually.
        if (! $record instanceof Model) {
            return true;
        }

        $context = $this->getContext();
        $resource = $context instanceof HasResource ? $context->getResourceClass() : null;

        if ($resource !== null) {
            return $resource::authorizes($ability, $record);
        }

        // Without a resource (custom tables) the model's policy applies, when it has the ability.
        return Gate::getPolicyFor($record) === null || Authorization::allows($ability, $record);
    }

    /**
     * @return Collection<int, Model>|LazyCollection<int, Model>|SupportCollection<int, mixed>
     */
    public function getRecords(): SupportCollection|LazyCollection
    {
        if ($this->resolvedRecords !== null) {
            return $this->resolvedRecords;
        }

        $records = $this->evaluate($this->records);

        // Rows of a table with custom `records()` arrive as a plain collection.
        if ($records instanceof SupportCollection && ! $records instanceof Collection) {
            $records = new Collection($records->values()->all());
        }

        if ($records === null && $this->selectedRecordsQuery !== null) {
            if (! $this->shouldFetchSelectedRecords()) {
                return $this->resolvedRecords = $this->loadSelectedKeys($this->selectedRecordsQuery);
            }

            $records = $this->loadSelectedRecords($this->selectedRecordsQuery);
        }

        // An action inside a bulk action's modal works on the same selection.
        if ($records === null && $this->parentAction instanceof BulkAction) {
            return $this->parentAction->getRecords();
        }

        $records ??= new Collection;

        if ($this->individualRecordAuthorization !== null) {
            $this->unauthorizedRecordsCount = 0;
            $records = $records->filter(function (Model|array $record): bool {
                if ($this->isRecordAuthorized($record)) {
                    return true;
                }

                $this->unauthorizedRecordsCount++;

                return false;
            })->values();
        }

        return $this->resolvedRecords = $records;
    }

    /**
     * Selected records the user wasn't allowed to act on (counted while the records are
     * read, so for chunked records only after the action has gone through them).
     */
    public function getUnauthorizedRecordsCount(): int
    {
        return $this->unauthorizedRecordsCount;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Collection<int, Model>|LazyCollection<int, Model>
     */
    protected function loadSelectedRecords(Builder $query): Collection|LazyCollection
    {
        $query = clone $query;
        $chunk = $this->getSelectedRecordsChunkSize();

        // Chunks are read by key, not by offset, so actions that delete or update the
        // records they go through don't skip any. The table's sort doesn't matter here.
        $records = $chunk === null
            ? $query->get()
            : $query->reorder()->lazyById($chunk, $query->getModel()->getQualifiedKeyName(), $query->getModel()->getKeyName());

        if ($this->selectedRecordsFilter !== null) {
            $records = $records->filter($this->selectedRecordsFilter)->values();
        }

        return $records;
    }

    /**
     * The selected keys, without loading models (`fetchSelectedRecords(false)`). Records are
     * still read (lazily) when the table limits which rows are selectable or each record is
     * authorised, so those checks keep applying.
     *
     * @param  Builder<Model>  $query
     * @return SupportCollection<int, mixed>
     */
    protected function loadSelectedKeys(Builder $query): SupportCollection
    {
        $query = clone $query;
        $model = $query->getModel();

        if (! $this->selectionTable?->hasRecordSelectabilityCheck() && $this->individualRecordAuthorization === null) {
            return $query->reorder()->orderBy($model->getQualifiedKeyName())->pluck($model->getQualifiedKeyName())->values();
        }

        $this->unauthorizedRecordsCount = 0;
        $keys = [];

        foreach ($query->reorder()->lazyById(500, $model->getQualifiedKeyName(), $model->getKeyName()) as $record) {
            if ($this->selectedRecordsFilter !== null && ! ($this->selectedRecordsFilter)($record)) {
                continue;
            }

            if (! $this->isRecordAuthorized($record)) {
                $this->unauthorizedRecordsCount++;

                continue;
            }

            $keys[] = $record->getKey();
        }

        return collect($keys);
    }

    /**
     * Whether `$records` holds the selected keys rather than models
     * (`fetchSelectedRecords(false)` on a table selection).
     */
    public function receivesSelectedKeys(): bool
    {
        return $this->records === null && $this->selectedRecordsQuery !== null && ! $this->shouldFetchSelectedRecords();
    }

    /**
     * Run a built-in bulk action over the selection: `$eachRecord` for every model, or, with
     * `fetchSelectedRecords(false)`, `$eachQuery` once per `$chunkSize` keys with a query
     * constrained to them (no models are loaded, so model events don't fire).
     *
     * @param  Enumerable<array-key, mixed>  $records
     * @param  Closure(Model): mixed  $eachRecord
     * @param  Closure(Builder<Model>): mixed  $eachQuery
     */
    protected function processSelectedRecords(Enumerable $records, Closure $eachRecord, Closure $eachQuery, int $chunkSize = 500): void
    {
        if (! $this->receivesSelectedKeys() || $this->selectedRecordsQuery === null) {
            $records->each(static function (mixed $record) use ($eachRecord): void {
                if ($record instanceof Model) {
                    $eachRecord($record);
                }
            });

            return;
        }

        $model = $this->selectedRecordsQuery->getModel();

        // The keys come from the selection query (tenant, tab and filter constraints
        // included), so a fresh query on them only reaches selected records.
        $records->chunk($chunkSize)->each(static function (Enumerable $keys) use ($model, $eachQuery): void {
            $eachQuery($model->newQuery()->whereKey($keys->values()->all()));
        });
    }

    /**
     * Refuse selections above the table's `maxSelectableRecords()` or across groups under
     * `selectGroupsOnly()`.
     *
     * @param  Builder<Model>  $query
     */
    protected function ensureSelectionIsAllowed(Builder $query): void
    {
        $table = $this->selectionTable;

        if ($table === null) {
            return;
        }

        if ($table->getMaxSelectableRecords() !== null) {
            $table->ensureSelectionIsAllowed((clone $query)->reorder()->count());
        }

        $group = $table->isSelectGroupsOnly() ? $table->getGrouping() : null;

        if ($group !== null) {
            $grouped = (clone $query)->reorder();

            if (($relationship = $group->getRelationshipName()) !== null) {
                $grouped->with($relationship);
            }

            $table->ensureSelectionIsAllowed(0, $grouped->lazyById(500, $grouped->getModel()->getQualifiedKeyName(), $grouped->getModel()->getKeyName()));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $data = [], array $arguments = []): mixed
    {
        $this->bulkProcessingFailures = [];
        $this->hasSentBulkProcessingNotification = false;

        // `maxSelectableRecords()` / `selectGroupsOnly()`: checked before anything runs, even
        // when the handler never reads `$records`.
        if ($this->selectedRecordsQuery !== null) {
            $this->ensureSelectionIsAllowed($this->selectedRecordsQuery);
        } elseif ($this->records !== null && $this->selectionTable !== null) {
            // Rows of custom `records()` arrive as a collection.
            $records = $this->getRecords();
            $this->selectionTable->ensureSelectionIsAllowed($records instanceof LazyCollection ? $records->count() : count($records), $records);
        }

        // Every selected record was refused: nothing to run.
        if ($this->individualRecordAuthorization !== null && $this->isAuthorized() && ! ($records = $this->getRecords()) instanceof LazyCollection && $records->isEmpty() && $this->unauthorizedRecordsCount > 0) {
            $this->sendUnauthorizedRecordsNotification();

            throw new Cancel;
        }

        $result = parent::execute($data, $arguments);

        $this->sendUnauthorizedRecordsNotification();

        // Failures reported by an action that never sent its notification still reach the user.
        if ($this->getBulkProcessingFailureCount() > 0 && ! $this->hasSentBulkProcessingNotification) {
            $this->sendFailureNotification();
        }

        return $result;
    }

    /**
     * A bulk action whose records partly failed sends its failure notification instead.
     */
    public function sendSuccessNotification(): void
    {
        if ($this->getBulkProcessingFailureCount() > 0) {
            $this->sendFailureNotification();

            return;
        }

        $this->hasSentBulkProcessingNotification = true;

        parent::sendSuccessNotification();
    }

    public function sendFailureNotification(): void
    {
        $this->hasSentBulkProcessingNotification = true;

        $failures = $this->getBulkProcessingFailureCount();
        $notification = $this->getFailureNotification();

        if ($failures === 0) {
            $notification?->send();

            return;
        }

        $total = $this->getBulkProcessingTotalCount();
        $success = max(0, $total - $failures);

        // Without a failure notification of its own: "3 of 5 records failed" (a warning
        // when some went through, an error when none did).
        $notification ??= ($success === 0 ? Notification::make()->danger() : Notification::make()->warning())
            ->title($success === 0
                ? trans_choice('alfakit::actions.bulk.all_failed', $total, ['count' => $total])
                : trans_choice('alfakit::actions.bulk.some_failed', $failures, ['failure' => $failures, 'total' => $total, 'success' => $success]));

        $messages = [];

        foreach ($this->bulkProcessingFailures as $failure) {
            $message = $this->evaluate($failure['message'], ['failureCount' => $failure['count'], 'totalCount' => $total, 'count' => $failure['count']]);

            if (is_string($message) && $message !== '') {
                $messages[] = $message;
            }
        }

        if ($messages !== []) {
            $notification->body(implode(' ', $messages));
        }

        $notification->send();
    }

    protected function sendUnauthorizedRecordsNotification(): void
    {
        $count = $this->unauthorizedRecordsCount;

        if ($count === 0) {
            return;
        }

        $title = $this->evaluate($this->unauthorizedRecordsNotificationTitle, ['count' => $count])
            ?? trans_choice('alfakit::actions.bulk.unauthorized_records', $count, ['count' => $count]);

        Notification::make()->warning()->title($title)->send();
    }
}
