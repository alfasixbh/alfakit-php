<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports;

use AlfaSix\Alfakit\Actions\Exports\Enums\ExportFormat;
use AlfaSix\Alfakit\Actions\Exports\Models\Export;
use AlfaSix\Alfakit\Notifications\Actions\Action as NotificationAction;
use AlfaSix\Alfakit\Notifications\Notification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\URL;

/**
 * The notifications and signed download links of an export.
 */
final class ExportNotifications
{
    /**
     * @param  array<ExportFormat>  $formats
     */
    public static function completed(Export $export, array $formats): Notification
    {
        /** @var class-string<Exporter> $exporter */
        $exporter = $export->exporter;

        $actions = [];

        foreach ($formats as $format) {
            $url = self::downloadUrl($export, $format);

            if ($url !== null && $export->hasFile($format)) {
                $actions[] = NotificationAction::make('download'.ucfirst($format->value))
                    ->label($format->getDownloadLabel())
                    ->icon('download')
                    ->url($url, shouldOpenInNewTab: true)
                    ->markAsRead();
            }
        }

        $notification = Notification::make()
            ->title($exporter::getCompletedNotificationTitle($export))
            ->body($exporter::getCompletedNotificationBody($export))
            ->status($export->successful_rows < $export->total_rows ? 'warning' : 'success')
            ->icon('file-output')
            ->persistent()
            ->actions($actions);

        return $exporter::modifyCompletedNotification($notification, $export);
    }

    /**
     * @param  array<ExportFormat>  $formats
     */
    public static function sendCompleted(Export $export, array $formats): void
    {
        $user = $export->user;

        if ($user instanceof Authenticatable) {
            self::completed($export, $formats)->sendToDatabase($user);
        }
    }

    /**
     * A signed, expiring download link. The download also checks that the signed-in user
     * started the export.
     */
    public static function downloadUrl(Export $export, ExportFormat $format): ?string
    {
        $panel = $export->getPanel();

        if ($panel === null) {
            return null;
        }

        return URL::temporarySignedRoute(
            $panel->generateRouteName('internal.exports.download'),
            now()->addMinutes((int) config('alfakit.import_export.download_link_lifetime', 10080)),
            ['export' => $export->getKey(), 'format' => $format->value],
        );
    }
}
