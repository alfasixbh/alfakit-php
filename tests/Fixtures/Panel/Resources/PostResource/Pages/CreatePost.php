<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;
}
