<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A CSV row that failed validation or could not be saved, with the reason.
 *
 * @property int $id
 * @property array<string, mixed> $data
 * @property string|null $validation_error
 */
class FailedImportRow extends Model
{
    protected $table = 'failed_import_rows';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
