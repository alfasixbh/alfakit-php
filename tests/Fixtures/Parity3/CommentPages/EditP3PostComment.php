<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\CommentPages;

use AlfaSix\Alfakit\Actions\DeleteAction;
use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\Parity3\P3PostCommentResource;

class EditP3PostComment extends EditRecord
{
    protected static string $resource = P3PostCommentResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
