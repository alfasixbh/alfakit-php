<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ResourceTabs\RtPostResource;

class EditRtPost extends EditRecord
{
    use ConfiguresRelationManagerTabs;

    protected static string $resource = RtPostResource::class;
}
