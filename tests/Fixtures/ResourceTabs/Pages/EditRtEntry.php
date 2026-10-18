<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtArchiveResource;

class EditRtEntry extends EditRecord
{
    protected static string $resource = RtArchiveResource::class;

    /** @var array<int, string> */
    public static array $seen = [];

    protected function getHeaderActions(): array
    {
        return [
            // Reports which registration serves the internal request.
            Action::make('whoami')->action(fn () => static::$seen[] = RtArchiveResource::getConfiguration()?->getKey() ?? 'none'),
        ];
    }
}
