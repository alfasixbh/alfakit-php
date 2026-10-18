<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Undo;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;

class ListUndoPosts extends ListRecords
{
    protected static string $resource = UndoPostResource::class;
}
