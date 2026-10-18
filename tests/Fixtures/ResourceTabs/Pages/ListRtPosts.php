<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPostResource;

class ListRtPosts extends ListRecords
{
    protected static string $resource = RtPostResource::class;
}
