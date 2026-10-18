<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Concerns;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * `HasAppAuthentication` for an Eloquent user with an `app_authentication_secret` text
 * column. The secret is stored encrypted with the application key and never serialised.
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait InteractsWithAppAuthentication
{
    public function initializeInteractsWithAppAuthentication(): void
    {
        $this->hidden[] = 'app_authentication_secret';
    }

    public function getAppAuthenticationSecret(): ?string
    {
        $value = $this->getAttribute('app_authentication_secret');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret === null ? null : Crypt::encryptString($secret)])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return (string) ($this->getAttribute('email') ?? $this->getAttribute('name') ?? $this->getKey());
    }
}
