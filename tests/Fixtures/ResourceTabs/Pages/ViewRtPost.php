<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages;

use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPostResource;

class ViewRtPost extends ViewRecord
{
    use ConfiguresRelationManagerTabs;

    protected static string $resource = RtPostResource::class;
}
