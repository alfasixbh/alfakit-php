<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

/**
 * Lets a user opt out of being impersonated (e.g. administrators). Users that don't
 * implement this can be impersonated by anyone allowed to impersonate.
 */
interface CanBeImpersonated
{
    public function canBeImpersonated(): bool;
}
