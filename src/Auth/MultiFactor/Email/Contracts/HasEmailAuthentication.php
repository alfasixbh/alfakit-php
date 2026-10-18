<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Email\Contracts;

/**
 * A user that can confirm sign-ins with a code sent to their email address.
 */
interface HasEmailAuthentication
{
    public function hasEmailAuthentication(): bool;

    public function toggleEmailAuthentication(bool $condition): void;
}
