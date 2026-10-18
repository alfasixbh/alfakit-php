<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;

class ListPageTableProjects extends ListRecords
{
    protected static string $resource = PageTableProjectResource::class;

    protected function getHeaderWidgets(): array
    {
        return [ProjectTableStats::class];
    }
}
