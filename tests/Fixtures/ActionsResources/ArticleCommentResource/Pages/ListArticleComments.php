<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleCommentResource;

class ListArticleComments extends ListRecords
{
    protected static string $resource = ArticleCommentResource::class;
}
