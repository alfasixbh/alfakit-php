<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy;

use AlfaSix\Alfakit\Pages\Dashboard;

class SalesDashboard extends Dashboard
{
    public function getWidgets(): array
    {
        return [SalesWidget::class];
    }
}
