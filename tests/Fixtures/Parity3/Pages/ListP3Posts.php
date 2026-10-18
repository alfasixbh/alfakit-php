<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PostResource;

class ListP3Posts extends ListRecords
{
    protected static string $resource = P3PostResource::class;
}
