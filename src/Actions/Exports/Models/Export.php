<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Models;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\Exporter;
use AlfaSix\Alfakit\Support\Concerns\BelongsToRunOwner;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * One run of an ExportAction or ExportBulkAction. Files live on `file_disk` under
 * `{directory}/{id}/`.
 *
 * @property int $id
 * @property string $exporter
 * @property string $file_disk
 * @property string $file_name
 * @property int $total_rows
 * @property int $processed_rows
 * @property int $successful_rows
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class Export extends Model
{
    use BelongsToRunOwner;

    protected $table = 'exports';

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
     * @param  array<string, string>  $columnMap
     * @param  array<string, mixed>  $options
     */
    public function getExporter(array $columnMap = [], array $options = []): Exporter
    {
        /** @var class-string<Exporter> $class */
        $class = $this->exporter;

        return new $class($this, $columnMap, $options);
    }

    /**
     * Rows that were not exported (`total_rows - successful_rows`), as the completed
     * notification of an `Exporter` reports them.
     */
    public function getFailedRowsCount(): int
    {
        return max(0, (int) $this->total_rows - (int) $this->successful_rows);
    }

    public function getFileDisk(): Filesystem
    {
        return Storage::disk($this->file_disk);
    }

    public function getFileDirectory(): string
    {
        return trim((string) config('alfakit.import_export.export_directory', 'alfakit-exports'), '/').'/'.$this->getKey();
    }

    public function getFilePath(ExportFormat $format): string
    {
        return $this->getFileDirectory().'/'.$this->file_name.'.'.$format->getExtension();
    }

    public function hasFile(ExportFormat $format): bool
    {
        return $this->getFileDisk()->exists($this->getFilePath($format));
    }

    /**
     * Remove the export's files along with the record.
     */
    public function deleteFiles(): void
    {
        $this->getFileDisk()->deleteDirectory($this->getFileDirectory());
    }
}
