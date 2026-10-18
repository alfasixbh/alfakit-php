<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * Where the database notifications bell sits in sidebar layouts.
 */
enum DatabaseNotificationsPosition: string
{
    case Topbar = 'topbar';
    case Sidebar = 'sidebar';
}
