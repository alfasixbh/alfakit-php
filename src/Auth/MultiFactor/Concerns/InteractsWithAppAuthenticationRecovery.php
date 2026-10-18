<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Auth\MultiFactor\Concerns;

use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * `HasAppAuthenticationRecovery` for an Eloquent user with an
 * `app_authentication_recovery_codes` text column (encrypted JSON of code hashes).
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait InteractsWithAppAuthenticationRecovery
{
    public function initializeInteractsWithAppAuthenticationRecovery(): void
    {
        $this->hidden[] = 'app_authentication_recovery_codes';
    }

    /**
     * @return array<string>|null
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        $value = $this->getAttribute('app_authentication_recovery_codes');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $codes = json_decode(Crypt::decryptString($value), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($codes) ? array_values(array_filter($codes, is_string(...))) : null;
    }

    /**
     * @param  array<string>|null  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->forceFill([
            'app_authentication_recovery_codes' => $codes === null ? null : Crypt::encryptString((string) json_encode(array_values($codes))),
        ])->save();
    }
}
