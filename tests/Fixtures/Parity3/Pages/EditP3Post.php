<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PostResource;

class EditP3Post extends EditRecord
{
    protected static string $resource = P3PostResource::class;
}
