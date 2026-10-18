<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Pages\Dashboard;

class PageTableDashboard extends Dashboard
{
    public function getWidgets(): array
    {
        return [StatusCountWidget::make(['status' => 'draft']), StatusCountWidget::make(['status' => 'archived'])];
    }
}
