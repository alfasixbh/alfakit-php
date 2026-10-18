<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Portal\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\Portal\MyPostResource;

class EditMyPost extends EditRecord
{
    protected static string $resource = MyPostResource::class;
}
