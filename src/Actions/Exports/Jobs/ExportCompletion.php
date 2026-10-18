<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Jobs;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\ExportNotifications;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Exports\Writers\CsvWriter;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxWriter;
use AlfaSix\Alfakit\Actions\Jobs\RestoresRunContext;
use DateTimeInterface;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Joins the chunk files (in chunk order) into the downloadable files, deletes the chunks
 * and sends the completion notification with signed download links.
 */
class ExportCompletion implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RestoresRunContext;
    use SerializesModels;

    /**
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     * @param  array<string>  $formats
     */
    public function __construct(
        public Export $export,
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
            $disk = $this->export->getFileDisk();
            $chunks = collect($disk->files($this->export->getFileDirectory().'/chunks'))
                ->filter(static fn (string $path): bool => str_ends_with($path, '.jsonl'))
                ->sort()
                ->values();

            $formats = array_values(array_filter(
                array_map(static fn (string $format): ?ExportFormat => ExportFormat::tryFrom($format), $this->formats),
                static fn (?ExportFormat $format): bool => $format !== null && ($format !== ExportFormat::Xlsx || XlsxWriter::isSupported()),
            ));

            $csv = in_array(ExportFormat::Csv, $formats, true) ? new CsvWriter($exporter->getCsvDelimiter()) : null;
            $xlsx = in_array(ExportFormat::Xlsx, $formats, true) ? new XlsxWriter : null;

            $headers = $exporter->getHeaders();
            $csv?->addRow($headers);

            $cellStyle = $xlsx === null ? null : $exporter->getXlsxCellStyle();

            if ($xlsx !== null) {
                $xlsx = $exporter->configureXlsxWriterAfterOpen($xlsx);
                $headerStyle = $exporter->getXlsxHeaderCellStyle();
                $xlsx->addRow($exporter->makeXlsxHeaderRow($headers, $cellStyle === null ? $headerStyle : $cellStyle->mergeWith($headerStyle)), isHeader: true);
            }

            foreach ($chunks as $path) {
                $stream = $disk->readStream($path);

                if (! is_resource($stream)) {
                    continue;
                }

                while (($line = fgets($stream)) !== false) {
                    $row = json_decode($line, true);

                    if (! is_array($row)) {
                        continue;
                    }

                    $csv?->addRow($row);
                    $xlsx?->addRow($exporter->makeXlsxRow($row, $cellStyle));
                }

                fclose($stream);
            }

            if ($csv !== null) {
                $stream = $csv->toStream();
                $disk->writeStream($this->export->getFilePath(ExportFormat::Csv), $stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($xlsx !== null) {
                $file = $exporter->configureXlsxWriterBeforeClose($xlsx)->close();
                $stream = fopen($file, 'r');

                if ($stream !== false) {
                    $disk->writeStream($this->export->getFilePath(ExportFormat::Xlsx), $stream);
                    fclose($stream);
                }

                @unlink($file);
            }

            $disk->deleteDirectory($this->export->getFileDirectory().'/chunks');

            ExportNotifications::sendCompleted($this->export, $formats);
        });
    }
}
