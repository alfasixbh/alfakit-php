<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Discovery;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;

class HiddenDiscoveryResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static bool $isDiscovered = false;
}
