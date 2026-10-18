<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Pages\Page;

class PageTableReport extends Page
{
    protected static ?string $slug = 'pt-report';

    protected function getHeaderWidgets(): array
    {
        return [StatusCountWidget::make(['status' => 'published']), PostTableStats::class];
    }
}
