<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ImportExport;

use AlfaSix\Alfakit\Actions\Imports\ImportColumn;
use AlfaSix\Alfakit\Actions\Imports\Importer;
use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Model;

/**
 * Importer with the nice-to-have hooks: a sensitive column, a custom completion
 * notification, a failed rows downloader and job settings.
 */
class ParityImporter extends Importer
{
    protected static ?string $model = Post::class;

    public static bool $customDownloader = false;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('title')->requiredMapping()->rules(['required', 'max:100']),
            ImportColumn::make('slug')->requiredMapping()->rules(['required', 'max:100']),
            ImportColumn::make('content')->sensitive(),
        ];
    }

    public function resolveRecord(): ?Model
    {
        return $this->newQuery()->firstOrNew(['slug' => $this->data['slug']]);
    }

    public static function getCompletedNotificationTitle(Import $import): string
    {
        return 'Posts imported';
    }

    public static function modifyCompletedNotification(Notification $notification, Import $import): Notification
    {
        return $notification->icon('rocket');
    }

    public static function getFailedRowsDownloader(): ?callable
    {
        return static::$customDownloader
            ? static fn (Import $import) => response('custom '.$import->failedRows()->count())
            : null;
    }

    public function getJobTags(): array
    {
        return [...parent::getJobTags(), 'posts'];
    }

    public function getJobBackoff(): int|array|null
    {
        return [5, 10];
    }
}
