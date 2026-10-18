<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Customizable;

use AlfaSix\Alfakit\Pages\Dashboard;

class CustomDashboard extends Dashboard
{
    protected static ?string $routePath = '/';

    public function getWidgets(): array
    {
        return [StatsA::class, StatsB::class];
    }
}
