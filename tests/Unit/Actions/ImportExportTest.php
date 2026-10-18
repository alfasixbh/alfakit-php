<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Actions\Exports\Writers\CsvWriter;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxWriter;
use AlfaSix\Alfakit\Actions\Imports\CsvReader;
use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Tables\TableQueryString;
use Illuminate\Http\Request;

it('reads CSV headers and rows, stripping the BOM and detecting the delimiter', function (): void {
    $reader = CsvReader::fromString("\xEF\xBB\xBFName;E-mail;;Name\nAda;ada@example.com;x;dup\n\n;;;\nBob;bob@example.com\n");

    expect($reader->getDelimiter())->toBe(';')
        ->and($reader->getHeaders())->toBe(['Name', 'E-mail', 'Column 3', 'Name 2'])
        ->and(iterator_to_array($reader->getRecords(), false))->toBe([
            ['Name' => 'Ada', 'E-mail' => 'ada@example.com', 'Column 3' => 'x', 'Name 2' => 'dup'],
            ['Name' => 'Bob', 'E-mail' => 'bob@example.com', 'Column 3' => null, 'Name 2' => null],
        ])
        ->and($reader->count())->toBe(2);
});

it('converts Windows-1252 text to UTF-8', function (): void {
    $reader = CsvReader::fromString("name\n".mb_convert_encoding('Crème brûlée', 'Windows-1252', 'UTF-8')."\n");

    expect(iterator_to_array($reader->getRecords(), false))->toBe([['name' => 'Crème brûlée']]);
});

it('guesses headers ignoring case and punctuation', function (): void {
    $column = ImportColumn::make('email_address')->label('Email address')->guess(['mail']);

    expect($column->guessHeader(['Name', 'E-Mail Address']))->toBe('E-Mail Address')
        ->and($column->guessHeader(['MAIL']))->toBe('MAIL')
        ->and($column->guessHeader(['Phone']))->toBeNull()
        ->and(ImportColumn::make('author.email')->guessHeader(['Email']))->toBe('Email');
});

it('casts CSV text', function (): void {
    expect(ImportColumn::make('a')->castState('  hi '))->toBe('hi')
        ->and(ImportColumn::make('a')->castState(''))->toBeNull()
        ->and(ImportColumn::make('a')->boolean()->castState('Yes'))->toBeTrue()
        ->and(ImportColumn::make('a')->boolean()->castState('off'))->toBeFalse()
        ->and(ImportColumn::make('a')->boolean()->castState('maybe'))->toBe('maybe')
        ->and(ImportColumn::make('a')->integer()->castState('42'))->toBe(42)
        ->and(ImportColumn::make('a')->integer()->castState('4.5'))->toBe('4.5')
        ->and(ImportColumn::make('a')->numeric(decimalPlaces: 2)->castState('3.14159'))->toBe(3.14)
        ->and(ImportColumn::make('a')->array()->integer()->castState('1, 2,,3'))->toBe([1, 2, 3])
        ->and(ImportColumn::make('a')->castStateUsing(fn (?string $state, mixed $originalState): string => strtoupper((string) $state).$originalState)->castState(' x'))->toBe('X x');
});

it('neutralises spreadsheet formulas in CSV cells', function (mixed $value, string $expected): void {
    expect(CsvWriter::escape($value))->toBe($expected);
})->with([
    ['=1+1', "'=1+1"],
    ['+cmd', "'+cmd"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ["\tx", "'\tx"],
    ['-12.5', '-12.5'],
    [-3, '-3'],
    ['plain', 'plain'],
    [true, '1'],
    [null, ''],
]);

it('names spreadsheet columns', function (): void {
    expect(array_map(XlsxWriter::columnName(...), [0, 25, 26, 51, 701, 702]))->toBe(['A', 'Z', 'AA', 'AZ', 'ZZ', 'AAA']);
});

it('writes a valid XLSX workbook', function (): void {
    $writer = new XlsxWriter;
    $writer->addRow(['Name', 'Count'], isHeader: true);
    $writer->addRow(['<Ada & "Bob">', 3]);
    $writer->addRow([null, 1.5]);
    $path = $writer->close();

    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $names = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $zip->close();
    unlink($path);

    expect($names)->toContain('[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml')
        ->and(simplexml_load_string($sheet))->not->toBeFalse()
        ->and($sheet)->toContain('&lt;Ada &amp; &quot;Bob&quot;&gt;', '<c r="B2"><v>3</v></c>', '<c r="B3"><v>1.5</v></c>', 's="1"');
});

it('only reads table-state keys from the page query string', function (): void {
    $request = Request::create('/_alfakit/actions', 'POST', [
        'tableQuery' => 'search=ada&filters[status][value]=draft&comments_sort=body&record=5&token=x&page=3',
        'token' => 'real',
    ]);

    TableQueryString::apply($request);

    expect($request->query())->toBe(['search' => 'ada', 'filters' => ['status' => ['value' => 'draft']], 'comments_sort' => 'body'])
        ->and($request->input('token'))->toBe('real')
        ->and($request->input('record'))->toBeNull();
});
