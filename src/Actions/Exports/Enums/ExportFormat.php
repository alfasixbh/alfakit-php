<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Enums;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Xlsx = 'xlsx';

    public function getExtension(): string
    {
        return $this->value;
    }

    public function getMimeType(): string
    {
        return match ($this) {
            self::Csv => 'text/csv',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }

    public function getDownloadLabel(): string
    {
        return __('alfakit::export.notifications.completed.download_'.$this->value);
    }
}
