<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtArchiveResource;

class ListRtEntries extends ListRecords
{
    protected static string $resource = RtArchiveResource::class;
}
