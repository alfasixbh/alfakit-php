<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * Where the global search field sits in sidebar layouts.
 */
enum GlobalSearchPosition: string
{
    case Topbar = 'topbar';
    case Sidebar = 'sidebar';
}
