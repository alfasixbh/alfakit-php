<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Models;

use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Support\Concerns\BelongsToRunOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of an ImportAction.
 *
 * @property int $id
 * @property string $importer
 * @property string $file_name
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $successful_rows
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class Import extends Model
{
    use BelongsToRunOwner;

    protected $table = 'imports';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'successful_rows' => 'integer',
        ];
    }

    /**
     * @return HasMany<FailedImportRow, $this>
     */
    public function failedRows(): HasMany
    {
        return $this->hasMany(FailedImportRow::class);
    }

    public function getFailedRowsCount(): int
    {
        return $this->failedRows()->count();
    }

    /**
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     */
    public function getImporter(array $columnMap = [], array $options = []): Importer
    {
        /** @var class-string<Importer> $class */
        $class = $this->importer;

        return new $class($this, $columnMap, $options);
    }
}
