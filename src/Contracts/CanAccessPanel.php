<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

use AlfaSix\Alfakit\Panel;

/**
 * Implement on the User model to control who may enter a panel. Required in production
 * unless the panel explicitly allows all authenticated users.
 */
interface CanAccessPanel
{
    public function canAccessPanel(Panel $panel): bool;
}
