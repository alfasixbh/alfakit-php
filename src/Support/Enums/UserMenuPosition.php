<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * Where the user menu sits in sidebar layouts.
 */
enum UserMenuPosition: string
{
    case Topbar = 'topbar';
    case Sidebar = 'sidebar';
}
