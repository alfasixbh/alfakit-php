<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\App\Contracts;

/**
 * A user that can sign in with single-use recovery codes when the authenticator app is
 * lost. Codes are stored hashed; each one works once.
 */
interface HasAppAuthenticationRecovery
{
    /**
     * @return array<string>|null Hashes of the unused recovery codes.
     */
    public function getAppAuthenticationRecoveryCodes(): ?array;

    /**
     * @param  array<string>|null  $codes  Hashes of the recovery codes.
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void;
}
