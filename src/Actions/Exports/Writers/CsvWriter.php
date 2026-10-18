<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Writers;

use RuntimeException;

/**
 * Writes rows to a CSV stream. Text that a spreadsheet would run as a formula (starting
 * with `=`, `+`, `-`, `@`, tab or carriage return) is prefixed with `'`, so an exported
 * value can never execute in the reader's spreadsheet app (CSV injection).
 */
final class CsvWriter
{
    /** @var resource */
    private $stream;

    public function __construct(private readonly string $delimiter = ',', bool $withByteOrderMark = true)
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Could not open a temporary stream for the CSV export.');
        }

        $this->stream = $stream;

        // Excel only reads UTF-8 CSV files correctly with a byte order mark.
        if ($withByteOrderMark) {
            fwrite($this->stream, "\xEF\xBB\xBF");
        }
    }

    /**
     * @param  array<int, string|int|float|bool|null>  $row
     */
    public function addRow(array $row): void
    {
        fputcsv($this->stream, array_map(self::escape(...), $row), $this->delimiter, '"', '');
    }

    public static function escape(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (! is_string($value)) {
            return (string) $value;
        }

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }

    /**
     * The written file as a rewound stream; the caller owns (and closes) it.
     *
     * @return resource
     */
    public function toStream()
    {
        rewind($this->stream);

        return $this->stream;
    }
}
