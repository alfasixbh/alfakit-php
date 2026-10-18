<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource;

class EditArticleComment extends EditRecord
{
    protected static string $resource = ArticleCommentResource::class;
}
