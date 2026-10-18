<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Clusters\Cluster;

class Settings extends Cluster
{
    protected static ?string $navigationIcon = 'settings';

    protected static ?int $navigationSort = 5;
}
