<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\Panel\Resources\PostResource;
use Closure;

class ListPosts extends ListRecords
{
    protected static string $resource = PostResource::class;

    /** Tests set this to give the list page `content()` components. */
    public static ?Closure $contentUsing = null;

    public function content(Schema $schema): Schema
    {
        return static::$contentUsing !== null ? (static::$contentUsing)($schema) : $schema;
    }
}
