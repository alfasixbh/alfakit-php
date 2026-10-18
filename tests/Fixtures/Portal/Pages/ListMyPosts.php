<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Portal\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\Portal\MyPostResource;

class ListMyPosts extends ListRecords
{
    protected static string $resource = MyPostResource::class;
}
