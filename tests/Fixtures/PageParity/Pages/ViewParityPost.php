<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageParity\Pages;

use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Tests\Fixtures\PageParity\ParityPostResource;
use Closure;

class ViewParityPost extends ViewRecord
{
    protected static string $resource = ParityPostResource::class;

    public static ?Closure $contentUsing = null;

    public function content(Schema $schema): Schema
    {
        return static::$contentUsing !== null ? (static::$contentUsing)($schema, $this) : $schema;
    }
}
