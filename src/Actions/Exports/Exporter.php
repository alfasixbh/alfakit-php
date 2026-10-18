<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxRow;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxStyle;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxWriter;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Support\ModelLabel;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use LogicException;

/**
 * Describes the columns of an export. Generate one with
 * `php artisan make:alfakit-exporter Product --generate`.
 *
 * ```php
 * class ProductExporter extends Exporter
 * {
 *     protected static ?string $model = Product::class;
 *
 *     public static function getColumns(): array
 *     {
 *         return [
 *             ExportColumn::make('id')->label('ID'),
 *             ExportColumn::make('name'),
 *             ExportColumn::make('category.name'),
 *         ];
 *     }
 * }
 * ```
 */
abstract class Exporter
{
    /** @var class-string<Model>|null */
    protected static ?string $model = null;

    /** @var array<string, ExportColumn>|null */
    protected ?array $cachedColumns = null;

    /**
     * @param  array<string, string>  $columnMap  Column name → header label, in file order
     * @param  array<string, mixed>  $options  Values of the options form
     */
    final public function __construct(
        protected Export $export,
        protected array $columnMap = [],
        protected array $options = [],
    ) {}

    /**
     * @return array<ExportColumn>
     */
    abstract public static function getColumns(): array;

    /**
     * Test the exporter on single records: `ProductExporter::test()->export($product)`.
     *
     * @param  array<string, string>|null  $columnMap  Column name → header label (default: the columns enabled by default)
     * @param  array<string, mixed>  $options  Values of the options form
     */
    public static function test(?array $columnMap = null, array $options = [], ?Export $export = null): Testing\TestableExport
    {
        return new Testing\TestableExport(static::class, $columnMap, $options, $export);
    }

    /**
     * Extra fields shown in the export modal; their values reach the exporter as
     * `$this->options`.
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
     * Adjust the query each chunk loads its records with (eager loads, extra columns).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function modifyQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * The formats offered for download (both by default).
     *
     * @return array<ExportFormat>
     */
    public function getFormats(): array
    {
        return [ExportFormat::Csv, ExportFormat::Xlsx];
    }

    /**
     * The file name without extension.
     */
    public function getFileName(Export $export): string
    {
        return 'export-'.$export->getKey().'-'.Str::slug(static::getPluralModelLabel());
    }

    /**
     * The title of the notification sent when the export finished.
     */
    public static function getCompletedNotificationTitle(Export $export): string
    {
        return __('alfakit::export.notifications.completed.title');
    }

    /**
     * Change the completion notification (icon, actions, status…). Return it, or a new one.
     */
    public static function modifyCompletedNotification(Notification $notification, Export $export): Notification
    {
        return $notification;
    }

    /**
     * Serve a format's file yourself: return a callable that receives the `Export` and the
     * `ExportFormat` and returns a response. Null keeps the built-in download.
     *
     * ```php
     * public static function getDownloader(ExportFormat $format): ?callable
     * {
     *     return $format === ExportFormat::Csv ? app(CustomCsvDownloader::class) : null;
     * }
     * ```
     */
    public static function getDownloader(ExportFormat $format): ?callable
    {
        return null;
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = trans_choice('alfakit::export.notifications.completed.body', $export->successful_rows, [
            'count' => Number::format($export->successful_rows, locale: app()->getLocale()),
        ]);

        $failed = $export->total_rows - $export->successful_rows;

        if ($failed > 0) {
            $body .= ' '.trans_choice('alfakit::export.notifications.completed.failed', $failed, [
                'count' => Number::format($failed, locale: app()->getLocale()),
            ]);
        }

        return $body;
    }

    /**
     * The values of one record, in column-map order.
     *
     * @return array<int, string|int|float|bool|null>
     */
    public function getRow(Model $record): array
    {
        $columns = $this->getCachedColumns();
        $row = [];

        foreach (array_keys($this->columnMap) as $name) {
            $row[] = isset($columns[$name]) ? $columns[$name]->getFormattedState($record) : null;
        }

        return $row;
    }

    /**
     * @return array<int, string>
     */
    public function getHeaders(): array
    {
        return array_values(array_map(strval(...), $this->columnMap));
    }

    /**
     * @return array<string, ExportColumn>
     */
    public function getCachedColumns(): array
    {
        if ($this->cachedColumns !== null) {
            return $this->cachedColumns;
        }

        $columns = [];

        foreach (static::getColumns() as $column) {
            $columns[$column->getName()] = $column->exporter($this);
        }

        return $this->cachedColumns = $columns;
    }

    /**
     * Relationships and aggregates the selected columns need, so chunks don't run N+1 queries.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyEagerLoading(Builder $query): Builder
    {
        $model = $query->getModel();
        $with = [];

        foreach (array_keys($this->columnMap) as $name) {
            $column = $this->getCachedColumns()[$name] ?? null;

            if ($column === null) {
                continue;
            }

            array_push($with, ...$column->getRelationshipsToEagerLoad($model));

            // `counts()`, `exists()`, `avg()`… (each column adds its own aggregate).
            $column->applyAggregate($query);
        }

        if ($with !== []) {
            $query->with(array_values(array_unique($with)));
        }

        return $query;
    }

    public function getExport(): Export
    {
        return $this->export;
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

    /**
     * Delimiter of the CSV file.
     */
    public function getCsvDelimiter(): string
    {
        return ',';
    }

    public static function getModelLabel(): string
    {
        return ModelLabel::singular(static::getModel());
    }

    public static function getPluralModelLabel(): string
    {
        return ModelLabel::plural(static::getModel());
    }

    public function getJobConnection(): ?string
    {
        return config('alfakit.import_export.queue_connection');
    }

    public function getJobQueue(): ?string
    {
        return config('alfakit.import_export.queue');
    }

    /**
     * Middleware of each export job.
     *
     * @return array<int, object>
     */
    public function getJobMiddleware(): array
    {
        return [];
    }

    /**
     * Retry a failed job until this time (null: no retries).
     */
    public function getJobRetryUntil(): ?DateTimeInterface
    {
        return null;
    }

    /**
     * Seconds to wait before a retry: one delay, or one per attempt.
     *
     * @return int|array<int, int>|null
     */
    public function getJobBackoff(): int|array|null
    {
        return null;
    }

    /**
     * Tags of the export jobs (shown by Horizon).
     *
     * @return array<int, string>
     */
    public function getJobTags(): array
    {
        return ["export{$this->export->getKey()}"];
    }

    /**
     * Dispatch the chunk jobs as one named job batch (needs Laravel's `job_batches`
     * table). Null (the default) dispatches them as separate jobs.
     */
    public function getJobBatchName(): ?string
    {
        return null;
    }

    // ---------------------------------------------------------------- XLSX

    /**
     * The style of every data cell of the XLSX file.
     */
    public function getXlsxCellStyle(): ?XlsxStyle
    {
        return null;
    }

    /**
     * The style of the header cells (bold by default).
     */
    public function getXlsxHeaderCellStyle(): ?XlsxStyle
    {
        return (new XlsxStyle)->setFontBold();
    }

    /**
     * Runs before the header row is written: add rows above it (a title), set column
     * widths…
     */
    public function configureXlsxWriterAfterOpen(XlsxWriter $writer): XlsxWriter
    {
        return $writer;
    }

    /**
     * Runs before the file is closed: freeze rows, name the sheet…
     */
    public function configureXlsxWriterBeforeClose(XlsxWriter $writer): XlsxWriter
    {
        return $writer;
    }

    /**
     * A data row of the XLSX file. Return cells with their own styles to style single
     * columns (`$this->columnMap` gives the column names in order):
     *
     * ```php
     * public function makeXlsxRow(array $values, ?XlsxStyle $style = null): XlsxRow
     * {
     *     $cells = [];
     *
     *     foreach (array_keys($this->columnMap) as $index => $column) {
     *         $cells[] = XlsxCell::fromValue($values[$index], $column === 'price' ? (new XlsxStyle)->setFormat('#,##0.00') : null);
     *     }
     *
     *     return XlsxRow::fromCells($cells, $style);
     * }
     * ```
     *
     * @param  array<int, string|int|float|bool|null>  $values
     */
    public function makeXlsxRow(array $values, ?XlsxStyle $style = null): XlsxRow
    {
        return XlsxRow::fromValues($values, $style);
    }

    /**
     * The header row of the XLSX file.
     *
     * @param  array<int, string>  $values
     */
    public function makeXlsxHeaderRow(array $values, ?XlsxStyle $style = null): XlsxRow
    {
        return XlsxRow::fromValues($values, $style);
    }
}
