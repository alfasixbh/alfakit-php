<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Navigation\SubNavigationPosition;
use AlfaSix\Alfakit\Pages\Page;

class BillingSettings extends Page
{
    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationGroup = 'Account';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Billing';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
}
