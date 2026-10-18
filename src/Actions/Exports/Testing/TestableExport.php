<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Testing;

use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Model;

/**
 * Runs an exporter on single records, without a queue, files or an `Export` record in the
 * database (Filament's `Exporter::test()`):
 *
 * ```php
 * expect(ProductExporter::test(['name' => 'Name', 'sku' => 'SKU'])->export($product))
 *     ->toBe(['Oak desk', 'DESK-01']);
 * ```
 *
 * Without a column map, every column enabled by default is exported, in declaration order.
 */
class TestableExport
{
    protected Export $export;

    /** @var array<string, string> */
    protected array $columnMap;

    /**
     * @param  class-string<Exporter>  $exporterClass
     * @param  array<string, string>|null  $columnMap  Column name → header label, in file order
     * @param  array<string, mixed>  $options  Values of the options form
     */
    public function __construct(
        protected string $exporterClass,
        ?array $columnMap = null,
        protected array $options = [],
        ?Export $export = null,
    ) {
        $this->columnMap = $columnMap ?? self::defaultColumnMap($exporterClass);
        $this->export = $export ?? self::makeExport($exporterClass);
    }

    /**
     * @param  class-string<Exporter>  $exporterClass
     * @return array<string, string>
     */
    protected static function defaultColumnMap(string $exporterClass): array
    {
        $map = [];

        foreach ($exporterClass::getColumns() as $column) {
            if ($column->isEnabledByDefault() && $column->isVisible()) {
                $map[$column->getName()] = $column->getLabel();
            }
        }

        return $map;
    }

    /**
     * @param  class-string<Exporter>  $exporterClass
     */
    protected static function makeExport(string $exporterClass): Export
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        $export = new Export;
        $export->setOwner($panel->auth()->user(), alfakit()->getTenant(), $panel);
        $export->fill(['exporter' => $exporterClass, 'file_name' => 'export', 'total_rows' => 1]);

        return $export;
    }

    public function getExporter(): Exporter
    {
        return $this->export->getExporter($this->columnMap, $this->options);
    }

    /**
     * The values of one record, in column-map order, as written to the file.
     *
     * @return array<int, string|int|float|bool|null>
     */
    public function export(Model $record): array
    {
        return $this->getExporter()->getRow($record);
    }

    /**
     * The header row.
     *
     * @return array<int, string>
     */
    public function headers(): array
    {
        return $this->getExporter()->getHeaders();
    }
}
