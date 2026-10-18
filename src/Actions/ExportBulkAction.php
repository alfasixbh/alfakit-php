<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\CanExportRecords;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Export the selected table records (or every filtered record with "select all").
 *
 * ```php
 * $table->bulkActions([ExportBulkAction::make()->exporter(ProductExporter::class)]);
 * ```
 */
class ExportBulkAction extends BulkAction
{
    use CanExportRecords;
    use Exports\Concerns\CanFakeExports;

    public static function getDefaultName(): string
    {
        return 'export';
    }

    public static function make(?string $name = null): static
    {
        return parent::make($name ?? static::getDefaultName());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExport();
        $this->label(__('alfakit::export.bulk_label'));

        $this->action(function (array $data, Collection $records, ExportBulkAction $action): void {
            $action->startExport($records->map(static fn (Model $record): mixed => $record->getKey())->values()->all(), $data);
        });
    }
}
