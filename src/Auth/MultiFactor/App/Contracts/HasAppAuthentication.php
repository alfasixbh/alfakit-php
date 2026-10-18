<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\App\Contracts;

/**
 * A user that can sign in with codes from an authenticator app. Store the secret
 * encrypted: `InteractsWithAppAuthentication` does this for an Eloquent user.
 */
interface HasAppAuthentication
{
    public function getAppAuthenticationSecret(): ?string;

    public function saveAppAuthenticationSecret(?string $secret): void;

    /**
     * The account name shown in the authenticator app (usually the email address).
     */
    public function getAppAuthenticationHolderName(): string;
}
