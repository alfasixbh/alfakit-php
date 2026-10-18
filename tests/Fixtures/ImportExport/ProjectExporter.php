<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Exports\ExportColumn;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;

class ProjectExporter extends Exporter
{
    protected static ?string $model = Project::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('name'),
        ];
    }
}
