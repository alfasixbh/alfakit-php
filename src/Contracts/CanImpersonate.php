<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

/**
 * A user that may sign in as other users with `ImpersonateAction`. Users that don't
 * implement this can never impersonate.
 */
interface CanImpersonate
{
    public function canImpersonate(): bool;
}
