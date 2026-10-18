<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PanelNav;

use AlfaSix\Alfakit\Pages\Page;

class GeneralSettings extends Page
{
    protected static ?string $cluster = Settings::class;

    protected static ?string $navigationIcon = 'sliders';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'General';
}
