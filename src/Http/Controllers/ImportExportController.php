<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Http\Controllers;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Exports\Writers\CsvWriter;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Actions\Imports\Models\FailedImportRow;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of import/export files. Every link is a signed, expiring URL from a
 * notification or the import modal, the route runs the panel's auth middleware, and the
 * files of a run are only served to the user who started it.
 */
class ImportExportController
{
    public function downloadExport(Request $request, string $export): Response
    {
        $run = Export::query()->find($export);

        abort_if($run === null || ! $run->isOwnedBy($this->user()), 404);

        $format = ExportFormat::tryFrom((string) $request->query('format'));

        abort_if($format === null || ! $run->isCompleted() || ! $run->hasFile($format), 404);

        // `Exporter::getDownloader()`: the exporter serves the file itself.
        $exporter = $run->exporter;
        $downloader = is_string($exporter) && is_subclass_of($exporter, \AlfaSix\Alfakit\Actions\Exports\Exporter::class) ? $exporter::getDownloader($format) : null;

        if ($downloader !== null) {
            $response = $downloader($run, $format);

            abort_unless($response instanceof Response, 500, 'An export downloader must return a response.');

            return $response;
        }

        return $run->getFileDisk()->download(
            $run->getFilePath($format),
            $run->file_name.'.'.$format->getExtension(),
            ['Content-Type' => $format->getMimeType()],
        );
    }

    public function downloadFailedRows(string $import): Response
    {
        $run = Import::query()->find($import);

        abort_if($run === null || ! $run->isOwnedBy($this->user()), 404);

        // `Importer::getFailedRowsDownloader()`: the importer serves the rows itself.
        $importer = $run->importer;
        $downloader = is_string($importer) && is_subclass_of($importer, Importer::class) ? $importer::getFailedRowsDownloader() : null;

        if ($downloader !== null) {
            $response = $downloader($run);

            abort_unless($response instanceof Response, 500, 'A failed rows downloader must return a response.');

            return $response;
        }

        $fileName = Str::beforeLast($run->file_name, '.') ?: 'import';

        return response()->streamDownload(function () use ($run): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            $headers = null;
            $errorHeader = __('alfakit::import.failed_rows.error');

            fwrite($output, "\xEF\xBB\xBF");

            $run->failedRows()->orderBy('id')->each(function (FailedImportRow $row) use ($output, &$headers, $errorHeader): void {
                $data = (array) $row->data;

                if ($headers === null) {
                    $headers = array_map(strval(...), array_keys($data));
                    fputcsv($output, array_map(CsvWriter::escape(...), [...$headers, $errorHeader]), ',', '"', '');
                }

                $values = array_map(static fn (string $header): mixed => $data[$header] ?? null, $headers);
                $values[] = $row->validation_error;

                fputcsv($output, array_map(static fn (mixed $value): string => CsvWriter::escape(is_scalar($value) || $value === null ? $value : json_encode($value)), $values), ',', '"', '');
            });

            fclose($output);
        }, "{$fileName}-failed-rows.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function downloadExample(Request $request): StreamedResponse
    {
        abort_if($this->user() === null, 403);

        $importer = (string) $request->query('importer');

        abort_unless(class_exists($importer) && is_subclass_of($importer, Importer::class), 404);

        $columns = $importer::getColumns();

        return response()->streamDownload(function () use ($columns): void {
            $output = fopen('php://output', 'w');

            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_map(static fn ($column): string => CsvWriter::escape($column->getExampleHeader()), $columns), ',', '"', '');

            $examples = array_map(static fn ($column): array => $column->getExamples(), $columns);
            $rows = $examples === [] ? 0 : max(array_map('count', $examples));

            for ($index = 0; $index < $rows; $index++) {
                fputcsv($output, array_map(static function (array $values) use ($index): string {
                    $value = $values[$index] ?? null;

                    return CsvWriter::escape(is_scalar($value) || $value === null ? $value : json_encode($value));
                }, $examples), ',', '"', '');
            }

            fclose($output);
        }, Str::kebab(class_basename($importer)).'-example.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function user(): ?\Illuminate\Contracts\Auth\Authenticatable
    {
        return alfakit()->getCurrentOrDefaultPanel()->auth()->user();
    }
}
