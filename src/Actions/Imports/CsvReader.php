<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports;

use Generator;
use RuntimeException;

/**
 * Streams a CSV file: the first row is the header, every following non-empty row is
 * returned keyed by header. Strips a UTF-8 byte order mark, converts Windows-1252 text
 * to UTF-8 and detects the delimiter (`,` `;` tab `|`) unless one is given.
 */
final class CsvReader
{
    /** @var array<string>|null */
    private ?array $headers = null;

    private string $resolvedDelimiter;

    /**
     * @param  resource  $stream
     * @param  int  $headerOffset  Rows above the header row to skip (titles, notes)
     */
    private function __construct(private $stream, ?string $delimiter, private int $headerOffset = 0)
    {
        $this->headerOffset = max(0, $headerOffset);
        $this->resolvedDelimiter = $delimiter ?? $this->detectDelimiter();
    }

    /**
     * @param  resource|null  $stream
     */
    public static function fromStream($stream, ?string $delimiter = null, int $headerOffset = 0): self
    {
        $copy = fopen('php://temp', 'r+');

        if (! is_resource($stream) || $copy === false) {
            throw new RuntimeException('The CSV file could not be read.');
        }

        // Remote disks hand out streams that can't seek; the reader needs to rewind.
        stream_copy_to_stream($stream, $copy);
        fclose($stream);
        rewind($copy);

        return new self($copy, $delimiter, $headerOffset);
    }

    public static function fromString(string $contents, ?string $delimiter = null, int $headerOffset = 0): self
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new RuntimeException('The CSV file could not be read.');
        }

        fwrite($stream, $contents);
        rewind($stream);

        return new self($stream, $delimiter, $headerOffset);
    }

    public function getDelimiter(): string
    {
        return $this->resolvedDelimiter;
    }

    /**
     * Header names, deduplicated (`Name`, `Name 2`) and with empty headers named after
     * their position.
     *
     * @return array<string>
     */
    public function getHeaders(): array
    {
        if ($this->headers !== null) {
            return $this->headers;
        }

        $this->rewindToHeader();

        $row = $this->readRow();
        $headers = [];

        foreach ($row ?? [] as $index => $header) {
            $header = trim((string) $header);

            if ($index === 0 && $this->headerOffset === 0) {
                $header = (string) preg_replace('/^\xEF\xBB\xBF/', '', $header);
            }

            $header = $header === '' ? __('alfakit::import.column_number', ['number' => $index + 1]) : $header;
            $unique = $header;
            $suffix = 2;

            while (in_array($unique, $headers, true)) {
                $unique = "{$header} {$suffix}";
                $suffix++;
            }

            $headers[] = $unique;
        }

        return $this->headers = $headers;
    }

    /**
     * @return Generator<int, array<string, string|null>>
     */
    public function getRecords(): Generator
    {
        $headers = $this->getHeaders();

        $this->rewindToHeader();
        $this->readRow();

        while (($row = $this->readRow()) !== null) {
            if ($row === [null] || array_filter($row, static fn (?string $value): bool => $value !== null && trim($value) !== '') === []) {
                continue;
            }

            $record = [];

            foreach ($headers as $index => $header) {
                $record[$header] = $row[$index] ?? null;
            }

            yield $record;
        }
    }

    public function count(): int
    {
        $count = 0;

        foreach ($this->getRecords() as $ignored) {
            $count++;
        }

        return $count;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * Rewind to the header row, past the `headerOffset` rows above it.
     */
    private function rewindToHeader(): void
    {
        rewind($this->stream);

        for ($row = 0; $row < $this->headerOffset; $row++) {
            if ($this->readRow() === null) {
                break;
            }
        }
    }

    /**
     * @return array<int, string|null>|null
     */
    private function readRow(): ?array
    {
        $row = fgetcsv($this->stream, null, $this->resolvedDelimiter, '"', '');

        if ($row === false) {
            return null;
        }

        return array_map(static function (?string $value): ?string {
            if ($value === null || mb_check_encoding($value, 'UTF-8')) {
                return $value;
            }

            return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }, $row);
    }

    private function detectDelimiter(): string
    {
        rewind($this->stream);

        // The header row decides; rows above it may be free text.
        for ($row = 0; $row < $this->headerOffset; $row++) {
            fgets($this->stream);
        }

        $line = (string) fgets($this->stream);
        rewind($this->stream);

        $counts = [];

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($line, $candidate);
        }

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ',';
    }
}
