<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports;

use AlfaSix\Alfakit\Actions\Imports\Exceptions\RowImportFailedException;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Actions\Imports\Testing\TestableImport;
use AlfaSix\Alfakit\Support\ModelLabel;
use AlfaSix\Alfakit\Support\TenantScope;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use LogicException;

/**
 * Describes how CSV rows become records. Generate one with
 * `php artisan make:alfakit-importer Product --generate`.
 *
 * ```php
 * class ProductImporter extends Importer
 * {
 *     protected static ?string $model = Product::class;
 *
 *     public static function getColumns(): array
 *     {
 *         return [
 *             ImportColumn::make('name')->requiredMapping()->rules(['required', 'max:255']),
 *             ImportColumn::make('price')->numeric()->rules(['numeric', 'min:0']),
 *         ];
 *     }
 *
 *     public function resolveRecord(): ?Model
 *     {
 *         return $this->newQuery()->firstOrNew(['sku' => $this->data['sku']]);
 *     }
 * }
 * ```
 *
 * Each row runs: remap → cast → `beforeValidate` → validate → `afterValidate` →
 * `resolveRecord()` → `beforeFill` → fill → `afterFill` → `beforeSave` →
 * `beforeCreate`/`beforeUpdate` → save (then BelongsToMany syncs) → `afterCreate`/`afterUpdate` → `afterSave`.
 * A `resolveRecord()` returning null skips the row.
 * Override any of those hook methods. Rows run in a database transaction each.
 */
abstract class Importer
{
    /** @var class-string<Model>|null */
    protected static ?string $model = null;

    /**
     * Filament compatibility: the failed-rows CSV is always protected against formula
     * injection (cells starting with `=`, `+`, `-`, `@`, a tab or a carriage return get a
     * leading `'`), whatever this is set to.
     */
    protected static bool $shouldPreventFormulaInjection = true;

    /** @var array<string, mixed> */
    protected array $data = [];

    /** @var array<string, mixed> */
    protected array $originalData = [];

    protected ?Model $record = null;

    /** @var array<string, ImportColumn>|null */
    protected ?array $cachedColumns = null;

    /**
     * @param  array<string, string>  $columnMap  Importer column name → CSV header
     * @param  array<string, mixed>  $options  Values of the options form
     */
    final public function __construct(
        protected Import $import,
        protected array $columnMap = [],
        protected array $options = [],
    ) {}

    /**
     * @return array<ImportColumn>
     */
    abstract public static function getColumns(): array;

    /**
     * Test the importer on single rows: `ProductImporter::test()->import([...])->assertImported()`.
     *
     * @param  array<string, string>|null  $columnMap  Importer column name → CSV header (default: each column's own name)
     * @param  array<string, mixed>  $options  Values of the options form
     */
    public static function test(?array $columnMap = null, array $options = [], ?Import $import = null): TestableImport
    {
        return new TestableImport(static::class, $columnMap, $options, $import);
    }

    /**
     * Filament compatibility: a no-op, since the failed-rows CSV is always protected
     * against formula injection.
     */
    public static function preventFormulaInjection(bool $condition = true): void {}

    /**
     * Always true: formula-injection protection can't be turned off.
     */
    public static function shouldPreventFormulaInjection(): bool
    {
        return true;
    }

    /**
     * Fields shown in the import modal under the column mapping (e.g. "Update existing
     * records"). Their values reach the importer as `$this->options`.
     *
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    public static function getOptionsFormComponents(): array
    {
        return [];
    }

    /**
     * @return class-string<Model>
     */
    public static function getModel(): string
    {
        return static::$model ?? throw new LogicException('Set protected static ?string $model on '.static::class.'.');
    }

    /**
     * The title of the notification sent when the import finished.
     */
    public static function getCompletedNotificationTitle(Import $import): string
    {
        return __('alfakit::import.notifications.completed.title');
    }

    /**
     * Change the completion notification (icon, actions, status…). Return it, or a new one.
     */
    public static function modifyCompletedNotification(\AlfaSix\Alfakit\Notifications\Notification $notification, Import $import): \AlfaSix\Alfakit\Notifications\Notification
    {
        return $notification;
    }

    /**
     * Serve the failed rows yourself (another format, extra columns): return a callable
     * that receives the `Import` and returns a response. Null keeps the built-in CSV.
     *
     * ```php
     * public static function getFailedRowsDownloader(): ?callable
     * {
     *     return fn (Import $import) => response()->streamDownload(...);
     * }
     * ```
     */
    public static function getFailedRowsDownloader(): ?callable
    {
        return null;
    }

    /**
     * The body of the database notification sent when the import finished.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $failed = $import->total_rows - $import->successful_rows;

        $body = trans_choice('alfakit::import.notifications.completed.body', $import->successful_rows, [
            'count' => Number::format($import->successful_rows, locale: app()->getLocale()),
        ]);

        if ($failed > 0) {
            $body .= ' '.trans_choice('alfakit::import.notifications.completed.failed', $failed, [
                'count' => Number::format($failed, locale: app()->getLocale()),
            ]);
        }

        return $body;
    }

    /**
     * Run one CSV row (keyed by CSV header). Throws `ValidationException` or
     * `RowImportFailedException` when the row can't be imported.
     *
     * @param  array<string, mixed>  $row
     */
    public function __invoke(array $row): void
    {
        $this->originalData = $row;
        $this->record = null;
        $this->data = $this->remapData($row);

        $this->castData();
        $this->callHook('beforeValidate');
        $this->validateData();
        $this->callHook('afterValidate');

        DB::transaction(function (): void {
            $record = $this->resolveRecord();

            // `resolveRecord()` returning null skips the row: nothing is written and the
            // row is not reported as failed.
            if ($record === null) {
                return;
            }

            $this->record = $record;
            $this->authorizeRecord($record);

            $this->callHook('beforeFill');
            $this->fillRecord();
            $this->callHook('afterFill');

            $isNew = ! $record->exists;

            $this->callHook('beforeSave');
            $this->callHook($isNew ? 'beforeCreate' : 'beforeUpdate');

            $this->saveRecord();
            $this->saveRelationships();

            $this->callHook($isNew ? 'afterCreate' : 'afterUpdate');
            $this->callHook('afterSave');
        });
    }

    /**
     * The record a row fills: a new one by default. Return an existing record (from
     * `$this->newQuery()`, which is limited to the current tenant) to update it, or null
     * to skip the row.
     */
    public function resolveRecord(): ?Model
    {
        $model = static::getModel();

        return new $model;
    }

    /**
     * A query for the importer's model, limited to the import's tenant.
     *
     * @return Builder<Model>
     */
    public function newQuery(): Builder
    {
        return TenantScope::apply(static::getModel()::query());
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function remapData(array $row): array
    {
        $data = [];

        foreach ($this->getCachedColumns() as $name => $column) {
            $header = $this->columnMap[$name] ?? null;

            if ($header === null || $header === '' || ! array_key_exists($header, $row)) {
                continue;
            }

            $data[$name] = $row[$header];
        }

        return $data;
    }

    protected function castData(): void
    {
        foreach ($this->getCachedColumns() as $name => $column) {
            if (! array_key_exists($name, $this->data)) {
                continue;
            }

            $this->data[$name] = $column->castState($this->data[$name]);
        }
    }

    protected function validateData(): void
    {
        $rules = [];
        $attributes = [];

        foreach ($this->getCachedColumns() as $name => $column) {
            $columnRules = $column->getValidationRules();
            $itemRules = $column->getNestedRecursiveValidationRules();

            if ($columnRules === [] && $itemRules === []) {
                continue;
            }

            if ($columnRules !== []) {
                $rules[$name] = $columnRules;
            }

            $attributes[$name] = $column->getValidationAttribute();

            if ($column->isArray()) {
                $attributes["{$name}.*"] = $column->getValidationAttribute();

                // `nestedRecursiveRules()`: each item of a multi-value cell.
                if ($itemRules !== []) {
                    $rules["{$name}.*"] = $itemRules;
                }
            }
        }

        if ($rules === []) {
            return;
        }

        Validator::make($this->data, $rules, $this->getValidationMessages(), $attributes)->validate();
    }

    /**
     * @return array<string, string>
     */
    public function getValidationMessages(): array
    {
        return [];
    }

    /**
     * Existing records are only updated when the import's user may `update` them, and only
     * inside the import's tenant. New records were authorised by the action (`create`).
     */
    protected function authorizeRecord(Model $record): void
    {
        if (! $record->exists) {
            return;
        }

        $tenant = alfakit()->getTenant();

        if ($tenant !== null && ! $this->newQuery()->whereKey($record->getKey())->exists()) {
            throw new RowImportFailedException(__('alfakit::import.failures.record_not_found'));
        }

        $policy = Gate::getPolicyFor($record);

        if ($policy !== null && method_exists($policy, 'update') && ! Gate::forUser($this->import->user)->check('update', $record)) {
            throw new RowImportFailedException(__('alfakit::import.failures.unauthorized'));
        }
    }

    protected function fillRecord(): void
    {
        $record = $this->record;

        if ($record === null) {
            return;
        }

        foreach ($this->getCachedColumns() as $column) {
            $column->flushPendingRelationship();
        }

        foreach ($this->getCachedColumns() as $name => $column) {
            if (! array_key_exists($name, $this->data)) {
                continue;
            }

            $state = $this->data[$name];

            if (($state === null || $state === []) && $column->isBlankStateIgnored()) {
                continue;
            }

            $column->fillRecord($record, $state);
        }
    }

    /**
     * BelongsToMany columns are synced once the record has a key.
     */
    protected function saveRelationships(): void
    {
        if ($this->record === null) {
            return;
        }

        foreach ($this->getCachedColumns() as $column) {
            $column->saveRelationship($this->record);
        }
    }

    protected function saveRecord(): void
    {
        $record = $this->record;

        if ($record === null) {
            return;
        }

        $tenant = alfakit()->getTenant();

        if ($record->exists || $tenant === null) {
            $record->save();

            return;
        }

        $this->associateRecordWithTenant($record, $tenant);
    }

    /**
     * New records of a tenant-owned model belong to the import's tenant, like records
     * created on a Create page.
     */
    protected function associateRecordWithTenant(Model $record, Model $tenant): void
    {
        $name = alfakit()->getCurrentOrDefaultPanel()->getTenantOwnershipRelationshipName();
        $relationship = method_exists($record, $name) ? $record->{$name}() : null;

        if ($relationship instanceof BelongsTo) {
            $relationship->associate($tenant);
        }

        $record->save();

        if ($relationship instanceof BelongsToMany) {
            $relationship->syncWithoutDetaching([$tenant->getKey()]);
        }
    }

    /**
     * Run a lifecycle hook: the importer's own method, then the methods traits add for it,
     * named after the hook and the trait (`afterSaveSyncsSearchIndex()` from
     * `SyncsSearchIndex`), like page hooks.
     */
    protected function callHook(string $hook): void
    {
        if (method_exists($this, $hook)) {
            $this->{$hook}();
        }

        foreach (class_uses_recursive($this) as $trait) {
            $method = $hook.class_basename($trait);

            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }

    /**
     * @return array<string, ImportColumn>
     */
    public function getCachedColumns(): array
    {
        if ($this->cachedColumns !== null) {
            return $this->cachedColumns;
        }

        $columns = [];

        foreach (static::getColumns() as $column) {
            $columns[$column->getName()] = $column->importer($this);
        }

        return $this->cachedColumns = $columns;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOriginalData(): array
    {
        return $this->originalData;
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }

    public function getImport(): Import
    {
        return $this->import;
    }

    /**
     * @return array<string, string>
     */
    public function getColumnMap(): array
    {
        return $this->columnMap;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public static function getModelLabel(): string
    {
        return ModelLabel::singular(static::getModel());
    }

    public static function getPluralModelLabel(): string
    {
        return ModelLabel::plural(static::getModel());
    }

    /**
     * Queue connection for the import jobs (null: the default connection).
     */
    public function getJobConnection(): ?string
    {
        return config('alfakit.import_export.queue_connection');
    }

    /**
     * Queue for the import jobs (null: the connection's default queue).
     */
    public function getJobQueue(): ?string
    {
        return config('alfakit.import_export.queue');
    }

    /**
     * Middleware of each chunk job (e.g. `new WithoutOverlapping("import{$id}")`).
     *
     * @return array<int, object>
     */
    public function getJobMiddleware(): array
    {
        return [];
    }

    /**
     * Retry a failed chunk until this time (null: no retries, each chunk runs once). Rows
     * of a retried chunk that were already imported run again, so make `resolveRecord()`
     * find existing records.
     */
    public function getJobRetryUntil(): ?DateTimeInterface
    {
        return null;
    }

    /**
     * Seconds to wait before retrying a chunk: one delay, or one per attempt.
     *
     * @return int|array<int, int>|null
     */
    public function getJobBackoff(): int|array|null
    {
        return null;
    }

    /**
     * Tags of the chunk jobs (shown by Horizon).
     *
     * @return array<int, string>
     */
    public function getJobTags(): array
    {
        return ["import{$this->import->getKey()}"];
    }

    /**
     * Dispatch the chunks as one named job batch (needs Laravel's `job_batches` table).
     * Null (the default) dispatches them as separate jobs.
     */
    public function getJobBatchName(): ?string
    {
        return null;
    }
}
