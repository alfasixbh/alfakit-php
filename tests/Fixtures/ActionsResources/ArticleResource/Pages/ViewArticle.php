<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Tests\Fixtures\ActionsResources\ArticleResource;

/**
 * A View page of the article (not registered in getPages()), for relation managers on
 * View pages.
 */
class ViewArticle extends ViewRecord
{
    protected static string $resource = ArticleResource::class;
}
