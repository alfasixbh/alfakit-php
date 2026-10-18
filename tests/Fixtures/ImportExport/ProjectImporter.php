<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use Illuminate\Database\Eloquent\Model;

class ProjectImporter extends Importer
{
    protected static ?string $model = Project::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('id')->integer(),
            ImportColumn::make('name')->requiredMapping()->rules(['required']),
        ];
    }

    /**
     * Deliberately unscoped: the importer must still refuse records of other tenants.
     */
    public function resolveRecord(): ?Model
    {
        return isset($this->data['id']) ? Project::query()->find($this->data['id']) : new Project;
    }

    protected function fillRecord(): void
    {
        $this->record?->setAttribute('name', $this->data['name']);
    }
}
