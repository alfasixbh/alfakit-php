<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\TableParity;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;

class TpListPosts extends ListRecords
{
    protected static string $resource = TpPostResource::class;
}
