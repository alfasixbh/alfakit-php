<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsActions\DocumentResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Tests\Fixtures\FormsActions\DocumentResource;

class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;
}
