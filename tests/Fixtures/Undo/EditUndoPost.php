<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Undo;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;

class EditUndoPost extends EditRecord
{
    protected static string $resource = UndoPostResource::class;

    protected static bool $isSaveUndoable = true;

    protected static ?bool $detectsConflicts = false;
}
