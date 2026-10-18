<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxCell;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxRow;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxStyle;
use AlfaSix\Alfakit\Actions\Exports\Writers\XlsxWriter;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

/**
 * Exporter with XLSX styling, a custom completion notification and a CSV downloader.
 */
class ParityExporter extends Exporter
{
    protected static ?string $model = Post::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('title'),
            ExportColumn::make('rating'),
            ExportColumn::make('status')->enabledByDefault(false),
        ];
    }

    public static function getCompletedNotificationTitle(Export $export): string
    {
        return 'Your posts are ready';
    }

    public static function modifyCompletedNotification(Notification $notification, Export $export): Notification
    {
        return $notification->icon('package');
    }

    public static function getDownloader(ExportFormat $format): ?callable
    {
        return $format === ExportFormat::Csv
            ? static fn (Export $export, ExportFormat $format) => response('custom csv '.$export->getKey())
            : null;
    }

    public function getXlsxCellStyle(): ?XlsxStyle
    {
        return (new XlsxStyle)->setFontName('Consolas');
    }

    public function getXlsxHeaderCellStyle(): ?XlsxStyle
    {
        return (new XlsxStyle)->setFontBold()->setBackgroundColor('#FFEE00');
    }

    public function makeXlsxRow(array $values, ?XlsxStyle $style = null): XlsxRow
    {
        $cells = [];

        foreach (array_keys($this->columnMap) as $index => $column) {
            $cells[] = XlsxCell::fromValue($values[$index] ?? null, $column === 'rating' ? (new XlsxStyle)->setFormat('0.00') : null);
        }

        return XlsxRow::fromCells($cells, $style);
    }

    public function configureXlsxWriterBeforeClose(XlsxWriter $writer): XlsxWriter
    {
        return $writer->setSheetName('Posts')->freezeRows(1)->setColumnWidth(30, 2);
    }

    public function getJobBatchName(): ?string
    {
        return null;
    }
}
