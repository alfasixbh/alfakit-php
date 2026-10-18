<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Portal\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Tests\Fixtures\Portal\MyPostResource;

class CreateMyPost extends CreateRecord
{
    protected static string $resource = MyPostResource::class;
}
