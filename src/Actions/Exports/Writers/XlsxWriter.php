<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Writers;

use RuntimeException;
use ZipArchive;

/**
 * A minimal streaming XLSX (Office Open XML) writer: one sheet, text as inline strings and
 * numbers as numbers, with cell styles (`XlsxStyle`), column widths, frozen rows and a
 * sheet name. Needs the `zip` PHP extension. Rows are written to a temporary file, so
 * memory stays flat for large exports.
 */
final class XlsxWriter
{
    /** @var resource */
    private $sheet;

    private string $sheetPath;

    private int $rowNumber = 0;

    private string $sheetName = 'Sheet1';

    private int $frozenRows = 0;

    /** @var array<int, float> 1-based column index → width in characters */
    private array $columnWidths = [];

    /** @var array<string, int> style key → cellXfs index */
    private array $styleIndexes = [];

    /** @var array<int, XlsxStyle> cellXfs index → style (index 0 is the default) */
    private array $styles = [];

    public function __construct()
    {
        if (! self::isSupported()) {
            throw new RuntimeException('XLSX exports need the PHP zip extension.');
        }

        $this->sheetPath = (string) tempnam(sys_get_temp_dir(), 'alfakit-xlsx-sheet');
        $sheet = fopen($this->sheetPath, 'w');

        if ($sheet === false) {
            throw new RuntimeException('Could not open a temporary file for the XLSX export.');
        }

        $this->sheet = $sheet;
    }

    public static function isSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * Add a row of values (with an optional style for every cell) or a prepared `XlsxRow`.
     * A header row without a style is bold.
     *
     * @param  array<int, string|int|float|bool|null>|XlsxRow  $row
     */
    public function addRow(array|XlsxRow $row, bool $isHeader = false, ?XlsxStyle $style = null): void
    {
        if (is_array($row)) {
            $row = XlsxRow::fromValues($row, $style ?? ($isHeader ? (new XlsxStyle)->setFontBold() : null));
        }

        $this->rowNumber++;
        $cells = '';

        foreach ($row->cells as $index => $cell) {
            $value = $cell->value;
            $reference = self::columnName($index).$this->rowNumber;
            $styleIndex = $this->registerStyle(($row->style ?? new XlsxStyle)->mergeWith($cell->style));
            $styleAttribute = $styleIndex > 0 ? " s=\"{$styleIndex}\"" : '';

            $cells .= match (true) {
                $value === null, $value === '' => $styleIndex > 0 ? "<c r=\"{$reference}\"{$styleAttribute}/>" : '',
                is_bool($value) => "<c r=\"{$reference}\" t=\"b\"{$styleAttribute}><v>".($value ? '1' : '0').'</v></c>',
                is_int($value), is_float($value) && is_finite($value) => "<c r=\"{$reference}\"{$styleAttribute}><v>{$value}</v></c>",
                default => "<c r=\"{$reference}\" t=\"inlineStr\"{$styleAttribute}><is><t xml:space=\"preserve\">".self::xml((string) $value).'</t></is></c>',
            };
        }

        fwrite($this->sheet, "<row r=\"{$this->rowNumber}\">{$cells}</row>");
    }

    /**
     * The number of rows written so far.
     */
    public function getRowCount(): int
    {
        return $this->rowNumber;
    }

    /**
     * The worksheet's tab name (at most 31 characters, without `\ / ? * [ ] :`).
     */
    public function setSheetName(string $name): self
    {
        $name = mb_substr(trim(str_replace(['\\', '/', '?', '*', '[', ']', ':'], '', $name)), 0, 31);
        $this->sheetName = $name === '' ? 'Sheet1' : $name;

        return $this;
    }

    public function getSheetName(): string
    {
        return $this->sheetName;
    }

    /**
     * Keep the first `$rows` rows (e.g. the header) visible while scrolling.
     */
    public function freezeRows(int $rows): self
    {
        $this->frozenRows = max(0, $rows);

        return $this;
    }

    /**
     * Width (in characters) of one or more columns, 1-based (`setColumnWidth(30, 1, 2)`).
     */
    public function setColumnWidth(float $width, int ...$columns): self
    {
        foreach ($columns as $column) {
            if ($column > 0 && $width > 0) {
                $this->columnWidths[$column] = $width;
            }
        }

        return $this;
    }

    /**
     * Finish the workbook and return the path of the temporary .xlsx file; the caller
     * deletes it.
     */
    public function close(): string
    {
        fclose($this->sheet);

        $path = (string) tempnam(sys_get_temp_dir(), 'alfakit-xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the XLSX file.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::xml($this->sheetName).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        // The sheet's settings (frozen rows, column widths) come before its rows.
        $sheetFile = (string) tempnam(sys_get_temp_dir(), 'alfakit-xlsx-full');
        $target = fopen($sheetFile, 'w');
        $rows = fopen($this->sheetPath, 'r');

        if ($target === false || $rows === false) {
            throw new RuntimeException('Could not assemble the XLSX sheet.');
        }

        fwrite($target, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$this->sheetViewsXml()
            .$this->columnsXml()
            .'<sheetData>');
        stream_copy_to_stream($rows, $target);
        fwrite($target, '</sheetData></worksheet>');
        fclose($rows);
        fclose($target);

        $zip->addFile($sheetFile, 'xl/worksheets/sheet1.xml');
        $zip->close();

        @unlink($this->sheetPath);
        @unlink($sheetFile);

        return $path;
    }

    private function registerStyle(XlsxStyle $style): int
    {
        if ($style->isEmpty()) {
            return 0;
        }

        $key = $style->getKey();

        if (! isset($this->styleIndexes[$key])) {
            $this->styles[count($this->styles) + 1] = $style;
            $this->styleIndexes[$key] = count($this->styles);
        }

        return $this->styleIndexes[$key];
    }

    private function sheetViewsXml(): string
    {
        if ($this->frozenRows === 0) {
            return '';
        }

        $topLeft = 'A'.($this->frozenRows + 1);

        return '<sheetViews><sheetView workbookViewId="0">'
            ."<pane ySplit=\"{$this->frozenRows}\" topLeftCell=\"{$topLeft}\" activePane=\"bottomLeft\" state=\"frozen\"/>"
            ."<selection pane=\"bottomLeft\" activeCell=\"{$topLeft}\" sqref=\"{$topLeft}\"/>"
            .'</sheetView></sheetViews>';
    }

    private function columnsXml(): string
    {
        if ($this->columnWidths === []) {
            return '';
        }

        ksort($this->columnWidths);
        $columns = '';

        foreach ($this->columnWidths as $column => $width) {
            $columns .= "<col min=\"{$column}\" max=\"{$column}\" width=\"{$width}\" customWidth=\"1\"/>";
        }

        return "<cols>{$columns}</cols>";
    }

    private function stylesXml(): string
    {
        $fonts = ['<font><sz val="11"/><name val="Calibri"/></font>'];
        $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>'];
        $numberFormats = [];
        $xfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];

        foreach ($this->styles as $style) {
            $font = ($style->bold ? '<b/>' : '')
                .($style->italic ? '<i/>' : '')
                .($style->strikethrough ? '<strike/>' : '')
                .($style->underline ? '<u/>' : '')
                .'<sz val="'.($style->fontSize ?? 11).'"/>'
                .($style->fontColor !== null ? '<color rgb="FF'.$style->fontColor.'"/>' : '')
                .'<name val="'.self::xml($style->fontName ?? 'Calibri').'"/>';
            $fontId = array_search("<font>{$font}</font>", $fonts, true);

            if ($fontId === false) {
                $fonts[] = "<font>{$font}</font>";
                $fontId = count($fonts) - 1;
            }

            $fillId = 0;

            if ($style->backgroundColor !== null) {
                $fill = '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$style->backgroundColor.'"/><bgColor indexed="64"/></patternFill></fill>';
                $fillId = array_search($fill, $fills, true);

                if ($fillId === false) {
                    $fills[] = $fill;
                    $fillId = count($fills) - 1;
                }
            }

            $numberFormatId = 0;

            if ($style->format !== null && $style->format !== '') {
                $numberFormatId = array_search($style->format, $numberFormats, true);

                if ($numberFormatId === false) {
                    $numberFormatId = 164 + count($numberFormats);
                    $numberFormats[$numberFormatId] = $style->format;
                }
            }

            $alignment = $style->alignment !== null || $style->wrapText
                ? '<alignment'.($style->alignment !== null ? " horizontal=\"{$style->alignment}\"" : '').($style->wrapText ? ' wrapText="1"' : '').'/>'
                : '';

            $xfs[] = "<xf numFmtId=\"{$numberFormatId}\" fontId=\"{$fontId}\" fillId=\"{$fillId}\" borderId=\"0\" xfId=\"0\""
                .($fontId > 0 ? ' applyFont="1"' : '')
                .($fillId > 0 ? ' applyFill="1"' : '')
                .($numberFormatId > 0 ? ' applyNumberFormat="1"' : '')
                .($alignment !== '' ? " applyAlignment=\"1\">{$alignment}</xf>" : '/>');
        }

        $numberFormatsXml = '';

        foreach ($numberFormats as $id => $code) {
            $numberFormatsXml .= "<numFmt numFmtId=\"{$id}\" formatCode=\"".self::xml($code).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($numberFormatsXml !== '' ? '<numFmts count="'.count($numberFormats).'">'.$numberFormatsXml.'</numFmts>' : '')
            .'<fonts count="'.count($fonts).'">'.implode('', $fonts).'</fonts>'
            .'<fills count="'.count($fills).'">'.implode('', $fills).'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="'.count($xfs).'">'.implode('', $xfs).'</cellXfs>'
            .'</styleSheet>';
    }

    /**
     * Spreadsheet column letters: 0 → A, 25 → Z, 26 → AA.
     */
    public static function columnName(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + (($index - 1) % 26)).$name;
        }

        return $name;
    }

    private static function xml(string $value): string
    {
        // XML 1.0 can't hold most control characters.
        $value = (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value);

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
