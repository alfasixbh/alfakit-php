<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Jobs;

use AlfaSix\Alfakit\Actions\Imports\Exceptions\RowImportFailedException;
use AlfaSix\Alfakit\Actions\Imports\ImportNotifications;
use AlfaSix\Alfakit\Actions\Imports\Models\FailedImportRow;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Actions\Jobs\RestoresRunContext;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Imports one chunk of CSV rows. The chunk that processes the last row marks the import
 * as completed and notifies the user.
 */
class ImportCsv implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RestoresRunContext;
    use SerializesModels;

    /** Rows are never imported twice: a failed job leaves its rows as failed rows. */
    public int $tries = 1;

    /**
     * @param  array<int, array<string, mixed>>  $rows  Rows keyed by CSV header
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public Import $import,
        public array $rows,
        public array $columnMap,
        public array $options = [],
        public ?string $locale = null,
    ) {}

    /**
     * Rows are never imported twice unless the importer asks for retries
     * (`getJobRetryUntil()`, which takes precedence over `$tries`).
     */
    public function retryUntil(): ?DateTimeInterface
    {
        return $this->getImporter()->getJobRetryUntil();
    }

    /**
     * @return int|array<int, int>|null
     */
    public function backoff(): int|array|null
    {
        return $this->getImporter()->getJobBackoff();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->getImporter()->getJobMiddleware();
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return $this->getImporter()->getJobTags();
    }

    /**
     * Not kept on the job: importers hold closures, which can't be serialised.
     */
    protected function getImporter(): \AlfaSix\Alfakit\Actions\Imports\Importer
    {
        return $this->import->getImporter($this->columnMap, $this->options);
    }

    public function handle(): void
    {
        $this->withinRunContext($this->import, $this->locale, function (): void {
            $importer = $this->import->getImporter($this->columnMap, $this->options);
            $sensitiveHeaders = $this->getSensitiveHeaders($importer);
            $successful = 0;

            foreach ($this->rows as $row) {
                try {
                    $importer($row);
                    $successful++;
                } catch (ValidationException $exception) {
                    $this->logFailedRow(array_diff_key($row, $sensitiveHeaders), implode(' ', array_merge(...array_values($exception->errors()))));
                } catch (RowImportFailedException $exception) {
                    $this->logFailedRow(array_diff_key($row, $sensitiveHeaders), $exception->getMessage());
                } catch (Throwable $exception) {
                    report($exception);

                    $this->logFailedRow(array_diff_key($row, $sensitiveHeaders), __('alfakit::import.failures.unexpected'));
                }
            }

            $this->import->addProcessedRows(count($this->rows), $successful);

            if ($this->import->markAsCompletedIfFinished()) {
                ImportNotifications::sendCompleted($this->import);
            }
        });
    }

    /**
     * The CSV headers mapped to `sensitive()` columns, whose values never reach the
     * failed rows.
     *
     * @return array<string, true>
     */
    protected function getSensitiveHeaders(\AlfaSix\Alfakit\Actions\Imports\Importer $importer): array
    {
        $headers = [];

        foreach ($importer->getCachedColumns() as $name => $column) {
            $header = $this->columnMap[$name] ?? null;

            if (is_string($header) && $header !== '' && $column->isSensitive()) {
                $headers[$header] = true;
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function logFailedRow(array $row, string $error): void
    {
        FailedImportRow::query()->create([
            'import_id' => $this->import->getKey(),
            'data' => $row,
            'validation_error' => $error,
        ]);
    }
}
