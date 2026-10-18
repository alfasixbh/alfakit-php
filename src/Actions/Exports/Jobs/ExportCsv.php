<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Jobs;

use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Jobs\RestoresRunContext;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Exports one chunk of records to an intermediate JSON-lines file. The records were
 * selected (filtered, searched, sorted and tenant-scoped) when the export started; the
 * chunk carries their keys in order. The chunk that processes the last row queues
 * ExportCompletion, which writes the CSV/XLSX files.
 */
class ExportCsv implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RestoresRunContext;
    use SerializesModels;

    public int $tries = 1;

    /**
     * @param  array<int, int|string>  $keys
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string>  $formats
     */
    public function __construct(
        public Export $export,
        public int $chunk,
        public array $keys,
        public array $columnMap,
        public array $options = [],
        public array $formats = ['csv', 'xlsx'],
        public ?string $locale = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return $this->export->getExporter($this->columnMap, $this->options)->getJobTags();
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return $this->export->getExporter($this->columnMap, $this->options)->getJobMiddleware();
    }

    /**
     * No retries unless the exporter asks for them (`getJobRetryUntil()` takes precedence
     * over `$tries`).
     */
    public function retryUntil(): ?DateTimeInterface
    {
        return $this->export->getExporter($this->columnMap, $this->options)->getJobRetryUntil();
    }

    /**
     * @return int|array<int, int>|null
     */
    public function backoff(): int|array|null
    {
        return $this->export->getExporter($this->columnMap, $this->options)->getJobBackoff();
    }

    public function handle(): void
    {
        $this->withinRunContext($this->export, $this->locale, function (): void {
            $exporter = $this->export->getExporter($this->columnMap, $this->options);
            $model = $exporter::getModel();

            // The keys came from the authorised, tenant-scoped table query, so global scopes
            // (soft deletes included) must not drop records the user selected.
            $query = (new $model)->newQueryWithoutScopes()->whereKey($this->keys);
            $query = $exporter::modifyQuery($exporter->applyEagerLoading($query));

            $records = $query->get()->keyBy(static fn (Model $record): string => (string) $record->getKey());

            $lines = '';
            $successful = 0;

            foreach ($this->keys as $key) {
                $record = $records->get((string) $key);

                if (! $record instanceof Model) {
                    continue;
                }

                try {
                    $lines .= json_encode($exporter->getRow($record), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n";
                    $successful++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            $this->export->getFileDisk()->put(static::getChunkPath($this->export, $this->chunk), $lines);
            $this->export->addProcessedRows(count($this->keys), $successful);

            if ($this->export->markAsCompletedIfFinished()) {
                dispatch(
                    (new ExportCompletion($this->export, $this->columnMap, $this->options, $this->formats, $this->locale))
                        ->onConnection($this->connection)
                        ->onQueue($this->queue),
                );
            }
        });
    }

    public static function getChunkPath(Export $export, int $chunk): string
    {
        return $export->getFileDirectory().'/chunks/'.str_pad((string) $chunk, 6, '0', STR_PAD_LEFT).'.jsonl';
    }
}
