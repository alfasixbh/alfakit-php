<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Imports\CsvReader;
use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Actions\Imports\ImportNotifications;
use AlfaSix\Alfakit\Actions\Imports\Jobs\ImportCsv;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Fieldset;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Support\Authorization;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\TemporaryUpload;
use Closure;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/**
 * Upload a CSV, map its columns to the importer's columns (guessed from the headers) and
 * import the rows in chunks on the queue. Rows that fail validation can be downloaded as
 * a CSV from the completion notification.
 *
 * ```php
 * ImportAction::make()->importer(ProductImporter::class)->maxRows(10_000)
 * ```
 */
class ImportAction extends Action
{
    use Imports\Concerns\CanFakeImports;

    /**
     * Accepted files, by the extension the upload endpoint derives from the detected type
     * (`text/csv` → `.csv`, `text/plain` → `.txt`, tab-separated → `.tsv`), never from the
     * client's file name.
     */
    public const CSV_FILE_TYPES = ['.csv', '.txt', '.tsv'];

    /** @var class-string<Importer>|null */
    protected ?string $importer = null;

    /** @var class-string<ImportCsv> */
    protected string $job = ImportCsv::class;

    protected int|Closure $chunkSize = 100;

    protected int|Closure|null $maxRows = null;

    protected int|Closure|null $maxFileSize = null;

    protected string|Closure|null $csvDelimiter = null;

    protected int|Closure $headerOffset = 0;

    /** @var array<mixed>|Closure */
    protected array|Closure $fileRules = [];

    /** @var array<string, mixed>|Closure */
    protected array|Closure $options = [];

    /** @var array<string, array<string>> */
    protected array $headersByUpload = [];

    public static function getDefaultName(): string
    {
        return 'import';
    }

    public static function make(?string $name = null): static
    {
        return parent::make($name ?? static::getDefaultName());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => __('alfakit::import.label', ['label' => $this->getImporterPluralLabel()]));
        $this->icon('upload');
        $this->color(Color::Gray);
        $this->modalHeading(fn (): string => __('alfakit::import.modal.heading', ['label' => $this->getImporterPluralLabel()]));
        $this->modalDescription(fn (): ?string => $this->getMaxRows() !== null
            ? __('alfakit::import.modal.max_rows', ['count' => Number::format((int) $this->getMaxRows(), locale: App::getLocale())])
            : null);
        $this->modalSubmitActionLabel(__('alfakit::import.modal.submit'));

        $this->authorize(fn (): bool => $this->importer !== null && Authorization::allows('create', $this->getImporter()::getModel()));

        $this->schema(fn (): array => $this->getImportFormComponents());

        $this->extraModalFooterActions(fn (): array => [
            Action::make('downloadExample')
                ->label(__('alfakit::import.modal.example'))
                ->icon('download')
                ->link()
                ->url($this->getExampleCsvUrl(), shouldOpenInNewTab: true),
        ]);

        $this->action(function (array $data, ImportAction $action): void {
            $action->startImport($data);
        });
    }

    // ---------------------------------------------------------------- Configuration

    /**
     * @param  class-string<Importer>  $importer
     */
    public function importer(string $importer): static
    {
        if (! is_subclass_of($importer, Importer::class)) {
            throw new LogicException("[{$importer}] must extend ".Importer::class.'.');
        }

        $this->importer = $importer;

        return $this;
    }

    /**
     * @return class-string<Importer>
     */
    public function getImporter(): string
    {
        return $this->importer ?? throw new LogicException("ImportAction [{$this->getName()}] needs an importer: ->importer(ProductImporter::class).");
    }

    /**
     * Use your own job class (extend ImportCsv) for each chunk.
     *
     * @param  class-string<ImportCsv>  $job
     */
    public function job(string $job): static
    {
        $this->job = $job;

        return $this;
    }

    /**
     * Rows per queued job (default 100).
     */
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
     * Refuse files with more data rows than this.
     */
    public function maxRows(int|Closure|null $rows): static
    {
        $this->maxRows = $rows;

        return $this;
    }

    public function getMaxRows(): ?int
    {
        $rows = $this->evaluate($this->maxRows) ?? config('alfakit.import_export.max_import_rows');

        return $rows === null ? null : (int) $rows;
    }

    /**
     * Largest accepted file in kilobytes (default: `alfakit.uploads.max_size_kb`).
     */
    public function maxFileSize(int|Closure|null $kilobytes): static
    {
        $this->maxFileSize = $kilobytes;

        return $this;
    }

    public function getMaxFileSize(): int
    {
        return (int) ($this->evaluate($this->maxFileSize) ?? config('alfakit.uploads.max_size_kb', 12288));
    }

    /**
     * The CSV delimiter. Detected from the header row when not set.
     */
    public function csvDelimiter(string|Closure|null $delimiter): static
    {
        $this->csvDelimiter = $delimiter;

        return $this;
    }

    public function getCsvDelimiter(): ?string
    {
        $delimiter = $this->evaluate($this->csvDelimiter);

        return is_string($delimiter) && strlen($delimiter) === 1 ? $delimiter : null;
    }

    /**
     * Rows above the header row to skip (titles or notes at the top of the file).
     */
    public function headerOffset(int|Closure $rows): static
    {
        $this->headerOffset = $rows;

        return $this;
    }

    public function getHeaderOffset(): int
    {
        return max(0, (int) $this->evaluate($this->headerOffset));
    }

    /**
     * Extra validation rules for the uploaded file, checked when the import starts:
     * `['max:1024', File::types(['csv'])->max(1024)]`.
     *
     * @param  array<mixed>|Closure  $rules
     */
    public function fileRules(array|Closure $rules): static
    {
        $this->fileRules = $rules;

        return $this;
    }

    /**
     * @return array<mixed>
     */
    public function getFileRules(): array
    {
        return (array) $this->evaluate($this->fileRules);
    }

    /**
     * Extra values passed to the importer as `$this->options`, merged with the options form.
     *
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

    // ---------------------------------------------------------------- Form

    /**
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>
     */
    protected function getImportFormComponents(): array
    {
        $importer = $this->getImporter();
        $columns = $importer::getColumns();

        return [
            FileUpload::make('file')
                ->label(__('alfakit::import.modal.file'))
                ->acceptedFileTypes(self::CSV_FILE_TYPES)
                ->maxSize($this->getMaxFileSize())
                ->storeFiles(false)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set, mixed $state) use ($columns): void {
                    $headers = $this->getUploadHeaders($state);

                    foreach ($columns as $column) {
                        $set("columnMap.{$column->getName()}", $column->guessHeader($headers), isAbsolute: true);
                    }
                }),
            Fieldset::make(__('alfakit::import.modal.columns'))
                ->statePath('columnMap')
                ->visible(fn (Get $get): bool => $this->getUploadHeaders($get('file', isAbsolute: true)) !== [])
                ->schema(array_map(fn (ImportColumn $column): Select => Select::make($column->getName())
                    ->label($column->getLabel())
                    ->helperText($column->getHelperText())
                    ->placeholder(__('alfakit::import.modal.skip_column'))
                    ->options(function (Get $get): array {
                        $headers = $this->getUploadHeaders($get('file', isAbsolute: true));

                        return array_combine($headers, $headers);
                    })
                    ->required($column->isMappingRequired()), $columns)),
            ...$importer::getOptionsFormComponents(),
        ];
    }

    /**
     * The header row of the uploaded CSV, read from the temporary upload (only the
     * uploader can read it).
     *
     * @return array<string>
     */
    protected function getUploadHeaders(mixed $state): array
    {
        $key = is_array($state) ? ($state[0] ?? null) : $state;

        if (! is_string($key) || ! TemporaryUpload::isKey($key)) {
            return [];
        }

        if (isset($this->headersByUpload[$key])) {
            return $this->headersByUpload[$key];
        }

        $upload = TemporaryUpload::find($key);

        if ($upload === null) {
            return $this->headersByUpload[$key] = [];
        }

        try {
            $reader = CsvReader::fromStream(TemporaryUpload::disk()->readStream($upload->path()), $this->getCsvDelimiter(), $this->getHeaderOffset());
            $headers = $reader->getHeaders();
            $reader->close();
        } catch (Throwable) {
            $headers = [];
        }

        return $this->headersByUpload[$key] = $headers;
    }

    protected function getExampleCsvUrl(): ?string
    {
        $panel = alfakit()->getCurrentPanel();

        if ($panel === null || $this->importer === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            $panel->generateRouteName('internal.imports.example'),
            now()->addMinutes((int) config('alfakit.import_export.download_link_lifetime', 10080)),
            ['importer' => $this->importer],
        );
    }

    // ---------------------------------------------------------------- Running

    /**
     * Read the validated upload, create the Import and dispatch one job per chunk.
     *
     * @param  array<string, mixed>  $data
     */
    public function startImport(array $data): Import
    {
        $importerClass = $this->getImporter();
        $key = is_array($data['file'] ?? null) ? ($data['file'][0] ?? null) : ($data['file'] ?? null);
        $upload = is_string($key) ? TemporaryUpload::find($key) : null;

        if ($upload === null) {
            throw ValidationException::withMessages(['file' => __('alfakit::forms.file_upload.unavailable')]);
        }

        $this->validateFileRules($upload);

        $reader = CsvReader::fromStream(TemporaryUpload::disk()->readStream($upload->path()), $this->getCsvDelimiter(), $this->getHeaderOffset());
        $headers = $reader->getHeaders();

        // Only real headers of this file can be mapped.
        $columnMap = [];

        foreach ((array) ($data['columnMap'] ?? []) as $column => $header) {
            if (is_string($column) && is_string($header) && in_array($header, $headers, true)) {
                $columnMap[$column] = $header;
            }
        }

        $maxRows = $this->getMaxRows();
        $rows = [];

        foreach ($reader->getRecords() as $row) {
            $rows[] = $row;

            if ($maxRows !== null && count($rows) > $maxRows) {
                $reader->close();

                throw ValidationException::withMessages(['file' => trans_choice('alfakit::import.validation.max_rows', $maxRows, [
                    'count' => Number::format($maxRows, locale: App::getLocale()),
                ])]);
            }
        }

        $reader->close();

        $panel = alfakit()->getCurrentPanel();

        $import = new Import;
        $import->setOwner($panel?->auth()->user(), alfakit()->getTenant(), $panel);
        $import->fill([
            'importer' => $importerClass,
            'file_name' => mb_substr($upload->originalName(), 0, 255),
            'total_rows' => count($rows),
        ])->save();

        // The rows travel with the jobs; the upload is no longer needed.
        $upload->delete();

        $options = [...$this->getOptions(), ...array_diff_key($data, ['file' => true, 'columnMap' => true])];
        $importer = $import->getImporter($columnMap, $options);

        $jobs = array_map(
            fn (array $chunk): ImportCsv => (new $this->job($import, $chunk, $columnMap, $options, App::getLocale()))
                ->onConnection($importer->getJobConnection())
                ->onQueue($importer->getJobQueue()),
            array_chunk($rows, $this->getChunkSize()),
        );

        $batchName = $importer->getJobBatchName();

        if ($batchName !== null && $jobs !== []) {
            // Failed rows are recorded per row, so one failed chunk doesn't cancel the rest.
            \Illuminate\Support\Facades\Bus::batch($jobs)
                ->name($batchName)
                ->allowFailures()
                ->onConnection($importer->getJobConnection())
                ->onQueue($importer->getJobQueue())
                ->dispatch();
        } else {
            foreach ($jobs as $job) {
                dispatch($job);
            }
        }

        if ($rows === [] && $import->markAsCompletedIfFinished()) {
            ImportNotifications::sendCompleted($import);
        }

        $import->refresh();

        // With the sync queue the import already finished; show the result right away.
        if ($import->isCompleted()) {
            ImportNotifications::completed($import)->send();
        } else {
            Notification::make()
                ->title(__('alfakit::import.notifications.started.title'))
                ->body(trans_choice('alfakit::import.notifications.started.body', $import->total_rows, [
                    'count' => Number::format($import->total_rows, locale: App::getLocale()),
                ]))
                ->info()
                ->send();
        }

        return $import;
    }

    /**
     * `fileRules()`: validate the upload as a file (size, type…) before reading it.
     *
     * @throws ValidationException
     */
    protected function validateFileRules(TemporaryUpload $upload): void
    {
        $rules = $this->getFileRules();

        if ($rules === []) {
            return;
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'alfakit-import');
        $source = TemporaryUpload::disk()->readStream($upload->path());
        $target = fopen($path, 'w');

        try {
            if (is_resource($source) && $target !== false) {
                stream_copy_to_stream($source, $target);
            }

            if ($target !== false) {
                fclose($target);
            }

            $file = new \Illuminate\Http\UploadedFile($path, $upload->originalName(), null, null, true);

            \Illuminate\Support\Facades\Validator::make(['file' => $file], ['file' => $rules], [], ['file' => __('alfakit::import.modal.file')])->validate();
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }

            @unlink($path);
        }
    }

    protected function getImporterPluralLabel(): string
    {
        if ($this->importer === null) {
            return '';
        }

        return $this->importer::getPluralModelLabel();
    }
}
