<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\CanExportRecords;
use AlfaSix\Alfakit\Support\TenantScope;
use AlfaSix\Alfakit\Tables\Table;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Export the records of the table it belongs to, with the table's filters, search and
 * sort applied, in chunks on the queue. The user gets a database notification with signed
 * download links for each format.
 *
 * ```php
 * $table->headerActions([ExportAction::make()->exporter(ProductExporter::class)]);
 * ```
 *
 * Outside a table (a page header action on a custom page) it exports every record of the
 * exporter's model in the current tenant.
 */
class ExportAction extends Action
{
    use CanExportRecords;
    use Exports\Concerns\CanFakeExports;

    protected ?Closure $modifyQueryUsing = null;

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

        $this->action(function (array $data, ExportAction $action): void {
            $action->startExport($action->getRecordKeysToExport(), $data);
        });
    }

    /**
     * Change the exported query: `fn (Builder $query) => $query->where('is_active', true)`.
     */
    public function modifyQueryUsing(?Closure $callback): static
    {
        $this->modifyQueryUsing = $callback;

        return $this;
    }

    /**
     * The table whose records are exported: the owning table, or the table of the page,
     * relation manager or widget the action is on.
     */
    public function getTable(): ?Table
    {
        $context = $this->getContext();

        if ($context instanceof Table) {
            return $context;
        }

        if (is_object($context) && method_exists($context, 'getTable')) {
            $table = $context->getTable();

            return $table instanceof Table ? $table : null;
        }

        return null;
    }

    /**
     * @return Builder<Model>
     */
    public function getExportQuery(): Builder
    {
        $query = $this->getTable()?->getFilteredSortedQuery();

        // Without a table, export the model's records of the current tenant.
        $query ??= TenantScope::apply($this->getExporter()::getModel()::query())->orderBy((new ($this->getExporter()::getModel()))->getQualifiedKeyName());

        if ($this->modifyQueryUsing !== null) {
            $query = $this->evaluate($this->modifyQueryUsing, ['query' => $query], [Builder::class => $query]) ?? $query;
        }

        return $query;
    }

    /**
     * The keys of every record to export, in table order.
     *
     * @return array<int, int|string>
     */
    public function getRecordKeysToExport(): array
    {
        $query = $this->getExportQuery();
        $model = $query->getModel();

        // Plain queries only need the key column; queries that select their own columns
        // (aggregates used for sorting) are read as they are.
        if ($query->getQuery()->columns === null) {
            return $query->toBase()->pluck($model->getQualifiedKeyName())->all();
        }

        $keys = [];

        foreach ($query->cursor() as $record) {
            $keys[] = $record->getKey();
        }

        return $keys;
    }
}
