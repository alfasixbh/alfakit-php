<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource;

class CreateArticleComment extends CreateRecord
{
    protected static string $resource = ArticleCommentResource::class;
}
