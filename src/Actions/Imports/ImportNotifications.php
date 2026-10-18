<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports;

use AlfaSix\Alfakit\Actions\Imports\Models\Import;
use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;

/**
 * The notifications and signed download links of an import.
 */
final class ImportNotifications
{
    public static function completed(Import $import): Notification
    {
        /** @var class-string<Importer> $importer */
        $importer = $import->importer;
        $failed = $import->total_rows - $import->successful_rows;

        $notification = Notification::make()
            ->title($importer::getCompletedNotificationTitle($import))
            ->body($importer::getCompletedNotificationBody($import))
            ->status(match (true) {
                $import->successful_rows === 0 && $failed > 0 => 'danger',
                $failed > 0 => 'warning',
                default => 'success',
            })
            ->icon('file-input');

        $url = $failed > 0 ? self::failedRowsUrl($import) : null;

        if ($url !== null) {
            $notification->actions([
                NotificationAction::make('downloadFailedRows')
                    ->label(trans_choice('alfakit::import.notifications.completed.download_failed_rows', $failed))
                    ->url($url, shouldOpenInNewTab: true)
                    ->markAsRead(),
            ]);
        }

        return $importer::modifyCompletedNotification($notification, $import);
    }

    public static function sendCompleted(Import $import): void
    {
        $user = $import->user;

        if ($user instanceof Authenticatable) {
            self::completed($import)->sendToDatabase($user);
        }
    }

    /**
     * A signed link to the failed rows as CSV. It also checks that the signed-in user
     * started the import.
     */
    public static function failedRowsUrl(Import $import): ?string
    {
        $panel = $import->getPanel();

        if ($panel === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            $panel->generateRouteName('internal.imports.failed-rows'),
            now()->addMinutes((int) config('alfakit.import_export.download_link_lifetime', 10080)),
            ['import' => $import->getKey()],
        );
    }
}
