<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Exports\ExportNotifications;
use AlfaSix\Alfakit\Actions\Exports\Jobs\ExportCompletion;
use AlfaSix\Alfakit\Actions\Exports\Jobs\ExportCsv;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxWriter;
use AlfaSix\Alfakit\Forms\Components\CheckboxList;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Section;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\Enums\Color;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use LogicException;

/**
 * Shared by ExportAction (the table's filtered, searched and sorted records) and
 * ExportBulkAction (the selected records).
 */
trait CanExportRecords
{
    /** @var class-string<Exporter>|null */
    protected ?string $exporter = null;

    /** @var class-string<ExportCsv> */
    protected string $job = ExportCsv::class;

    /** @var array<ExportFormat>|Closure|null */
    protected array|Closure|null $formats = null;

    protected bool|Closure $hasColumnMapping = true;

    protected bool|Closure $enablesVisibleTableColumnsByDefault = false;

    protected int|Closure $columnMappingColumns = 2;

    protected int|Closure $chunkSize = 100;

    protected int|Closure|null $maxRows = null;

    protected string|Closure|null $fileName = null;

    protected string|Closure|null $fileDisk = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $options = [];

    protected function setUpExport(): void
    {
        $this->label(fn (): string => __('alfakit::export.label', ['label' => $this->getExporterPluralLabel()]));
        $this->icon('download');
        $this->color(Color::Gray);
        $this->modalHeading(fn (): string => __('alfakit::export.modal.heading', ['label' => $this->getExporterPluralLabel()]));
        $this->modalSubmitActionLabel(__('alfakit::export.modal.submit'));
        $this->requiresConfirmation(fn (): bool => ! $this->hasColumnMapping() && $this->exporter !== null && $this->exporter::getOptionsFormComponents() === []);
        $this->modalDescription(fn (): ?string => $this->hasColumnMapping() ? null : __('alfakit::export.modal.description'));

        $this->authorize(fn (): bool => $this->exporter !== null && Authorization::allows('viewAny', $this->getExporter()::getModel()));

        $this->schema(fn (): array => $this->getExportFormComponents());
    }

    /**
     * @param  class-string<Exporter>  $exporter
     */
    public function exporter(string $exporter): static
    {
        if (! is_subclass_of($exporter, Exporter::class)) {
            throw new LogicException("[{$exporter}] must extend ".Exporter::class.'.');
        }

        $this->exporter = $exporter;

        return $this;
    }

    /**
     * @return class-string<Exporter>
     */
    public function getExporter(): string
    {
        return $this->exporter ?? throw new LogicException("Export action [{$this->getName()}] needs an exporter: ->exporter(ProductExporter::class).");
    }

    /**
     * @param  class-string<ExportCsv>  $job
     */
    public function job(string $job): static
    {
        $this->job = $job;

        return $this;
    }

    /**
     * The downloadable formats (default: the exporter's `getFormats()`, CSV and XLSX).
     *
     * @param  array<ExportFormat>|Closure|null  $formats
     */
    public function formats(array|Closure|null $formats): static
    {
        $this->formats = $formats;

        return $this;
    }

    /**
     * Let the user pick the exported columns (default true).
     */
    public function columnMapping(bool|Closure $condition = true): static
    {
        $this->hasColumnMapping = $condition;

        return $this;
    }

    public function hasColumnMapping(): bool
    {
        return (bool) $this->evaluate($this->hasColumnMapping);
    }

    /**
     * Pre-select only the export columns whose table column is visible right now (and
     * that are `enabledByDefault()`).
     */
    public function enableVisibleTableColumnsByDefault(bool|Closure $condition = true): static
    {
        $this->enablesVisibleTableColumnsByDefault = $condition;

        return $this;
    }

    /**
     * Grid columns of the column selection on large screens (default 2).
     */
    public function columnMappingColumns(int|Closure $columns): static
    {
        $this->columnMappingColumns = $columns;

        return $this;
    }

    public function getColumnMappingColumns(): int
    {
        return max(1, (int) $this->evaluate($this->columnMappingColumns));
    }

    /**
     * The export columns ticked when the modal opens.
     *
     * @param  array<ExportColumn>  $columns
     * @return array<int, string>
     */
    protected function getDefaultExportColumnNames(array $columns): array
    {
        $visible = null;

        if ($this->evaluate($this->enablesVisibleTableColumnsByDefault)) {
            $table = $this->getExportTable();
            $visible = $table === null ? null : array_map(strval(...), array_keys($table->getVisibleColumns()));
        }

        return array_values(array_map(
            static fn (ExportColumn $column): string => $column->getName(),
            array_filter($columns, static fn (ExportColumn $column): bool => $column->isEnabledByDefault() && ($visible === null || in_array($column->getName(), $visible, true))),
        ));
    }

    /**
     * The table the action belongs to (its context), if any.
     */
    protected function getExportTable(): ?\AlfaSix\Alfakit\Tables\Table
    {
        $context = $this->getContext();

        if ($context instanceof \AlfaSix\Alfakit\Tables\Table) {
            return $context;
        }

        if (is_object($context) && method_exists($context, 'getTable')) {
            $table = $context->getTable();

            return $table instanceof \AlfaSix\Alfakit\Tables\Table ? $table : null;
        }

        return null;
    }

    public function chunkSize(int|Closure $size): static
    {
        $this->chunkSize = $size;

        return $this;
    }

    public function getChunkSize(): int
    {
        return max(1, (int) $this->evaluate($this->chunkSize));
    }

    /**
     * Refuse exports of more records than this.
     */
    public function maxRows(int|Closure|null $rows): static
    {
        $this->maxRows = $rows;

        return $this;
    }

    public function getMaxRows(): ?int
    {
        $rows = $this->evaluate($this->maxRows) ?? config('alfakit.import_export.max_export_rows');

        return $rows === null ? null : (int) $rows;
    }

    /**
     * The file name without extension; a closure receives `$export`.
     */
    public function fileName(string|Closure|null $name): static
    {
        $this->fileName = $name;

        return $this;
    }

    /**
     * The disk export files are written to (default `alfakit.import_export.export_disk`).
     * Use a private disk: files are only served through signed download links.
     */
    public function fileDisk(string|Closure|null $disk): static
    {
        $this->fileDisk = $disk;

        return $this;
    }

    public function getFileDisk(): string
    {
        return (string) ($this->evaluate($this->fileDisk) ?? config('alfakit.import_export.export_disk', 'local'));
    }

    /**
     * @param  array<string, mixed>|Closure  $options
     */
    public function options(array|Closure $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return (array) $this->evaluate($this->options);
    }

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    protected function getExportFormComponents(): array
    {
        $exporter = $this->getExporter();
        $components = [];

        if ($this->hasColumnMapping()) {
            $columns = $this->getVisibleExportColumns();

            $components[] = CheckboxList::make('columns')
                ->label(__('alfakit::export.modal.columns'))
                ->options(array_combine(
                    array_map(static fn (ExportColumn $column): string => $column->getName(), $columns),
                    array_map(static fn (ExportColumn $column): string => $column->getLabel(), $columns),
                ))
                ->default($this->getDefaultExportColumnNames($columns))
                ->bulkToggleable()
                ->gridColumns($this->getColumnMappingColumns())
                ->live()
                ->required();

            // The header of each ticked column can be renamed for this export.
            $components[] = Section::make(__('alfakit::export.modal.column_labels'))
                ->collapsible()
                ->collapsed()
                ->statePath('columnLabels')
                ->visible(fn (Get $get): bool => array_filter((array) $get('columns', isAbsolute: true)) !== [])
                ->columns($this->getColumnMappingColumns())
                ->schema(array_map(static fn (ExportColumn $column): TextInput => TextInput::make($column->getName())
                    ->label($column->getLabel())
                    ->placeholder($column->getLabel())
                    ->maxLength(255)
                    ->visible(static fn (Get $get): bool => in_array($column->getName(), array_map(strval(...), (array) $get('columns', isAbsolute: true)), true)), $columns));
        }

        return [...$components, ...$exporter::getOptionsFormComponents()];
    }

    /**
     * The exporter's columns without the `hidden()` ones.
     *
     * @return array<int, ExportColumn>
     */
    protected function getVisibleExportColumns(): array
    {
        return array_values(array_filter($this->getExporter()::getColumns(), static fn (ExportColumn $column): bool => $column->isVisible()));
    }

    /**
     * Column name → header, in the exporter's column order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function resolveColumnMap(array $data): array
    {
        $selected = $this->hasColumnMapping() ? array_map(strval(...), (array) ($data['columns'] ?? [])) : null;
        $labels = $this->hasColumnMapping() ? (array) ($data['columnLabels'] ?? []) : [];
        $map = [];

        // Hidden columns are never exported, even when the request names them.
        foreach ($this->getVisibleExportColumns() as $column) {
            $name = $column->getName();

            if ($selected === null ? $column->isEnabledByDefault() : in_array($name, $selected, true)) {
                // A label the user typed in the modal replaces the column's header.
                $label = $labels[$name] ?? null;
                $map[$name] = is_string($label) && trim($label) !== '' ? mb_substr(trim($label), 0, 255) : $column->getLabel();
            }
        }

        return $map;
    }

    /**
     * @return array<ExportFormat>
     */
    protected function resolveFormats(Exporter $exporter): array
    {
        $formats = $this->evaluate($this->formats) ?? $exporter->getFormats();

        return array_values(array_filter(
            $formats,
            static fn (mixed $format): bool => $format instanceof ExportFormat && ($format !== ExportFormat::Xlsx || XlsxWriter::isSupported()),
        ));
    }

    /**
     * Create the Export and queue one job per chunk of record keys (already authorised and
     * tenant-scoped by the caller, in export order).
     *
     * @param  array<int, int|string>  $keys
     * @param  array<string, mixed>  $data
     */
    protected function startExport(array $keys, array $data): ?Export
    {
        $exporterClass = $this->getExporter();
        $maxRows = $this->getMaxRows();

        if ($maxRows !== null && count($keys) > $maxRows) {
            Notification::make()
                ->title(__('alfakit::export.notifications.max_rows.title'))
                ->body(trans_choice('alfakit::export.notifications.max_rows.body', $maxRows, [
                    'count' => Number::format($maxRows, locale: App::getLocale()),
                ]))
                ->danger()
                ->send();

            $this->halt();
        }

        $columnMap = $this->resolveColumnMap($data);
        $options = [...$this->getOptions(), ...array_diff_key($data, ['columns' => true, 'columnLabels' => true])];
        $panel = alfakit()->getCurrentPanel();

        $export = new Export;
        $export->setOwner($panel?->auth()->user(), alfakit()->getTenant(), $panel);
        $export->fill([
            'exporter' => $exporterClass,
            'file_disk' => $this->getFileDisk(),
            'file_name' => 'export',
            'total_rows' => count($keys),
        ])->save();

        $exporter = $export->getExporter($columnMap, $options);
        $name = $this->evaluate($this->fileName, ['export' => $export]) ?? $exporter->getFileName($export);
        // The name becomes a path segment: keep it to safe characters.
        $export->forceFill(['file_name' => trim((string) preg_replace('/[^\pL\pN._-]+/u', '-', (string) $name), '-.') ?: 'export'])->save();

        $formats = array_map(static fn (ExportFormat $format): string => $format->value, $this->resolveFormats($exporter));
        $locale = App::getLocale();
        $connection = $exporter->getJobConnection();
        $queue = $exporter->getJobQueue();

        $jobs = [];

        foreach (array_chunk($keys, $this->getChunkSize()) as $index => $chunk) {
            $jobs[] = (new $this->job($export, $index, $chunk, $columnMap, $options, $formats, $locale))->onConnection($connection)->onQueue($queue);
        }

        $batchName = $exporter->getJobBatchName();

        if ($batchName !== null && $jobs !== []) {
            \Illuminate\Support\Facades\Bus::batch($jobs)->name($batchName)->allowFailures()->onConnection($connection)->onQueue($queue)->dispatch();
        } else {
            foreach ($jobs as $job) {
                dispatch($job);
            }
        }

        if ($keys === [] && $export->markAsCompletedIfFinished()) {
            dispatch((new ExportCompletion($export, $columnMap, $options, $formats, $locale))->onConnection($connection)->onQueue($queue));
        }

        $export->refresh();

        // With the sync queue the files already exist; offer them right away.
        if ($export->isCompleted() && array_filter($formats, static fn (string $format): bool => $export->hasFile(ExportFormat::from($format))) !== []) {
            ExportNotifications::completed($export, array_map(ExportFormat::from(...), $formats))->send();
        } else {
            Notification::make()
                ->title(__('alfakit::export.notifications.started.title'))
                ->body(trans_choice('alfakit::export.notifications.started.body', $export->total_rows, [
                    'count' => Number::format($export->total_rows, locale: App::getLocale()),
                ]))
                ->info()
                ->send();
        }

        return $export;
    }

    protected function getExporterPluralLabel(): string
    {
        return $this->exporter === null ? '' : $this->exporter::getPluralModelLabel();
    }
}
