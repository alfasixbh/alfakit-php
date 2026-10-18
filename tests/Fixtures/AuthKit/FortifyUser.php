<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\AuthKit;

use AlfaSix\Alfakit\Tests\Fixtures\Models\MfaUser;

/**
 * A user with Fortify-style two-factor authentication (`two_factor_secret`,
 * `two_factor_recovery_codes`), as Fortify's `TwoFactorAuthenticatable` trait provides.
 */
class FortifyUser extends MfaUser
{
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        return $this->getAttribute('two_factor_secret') !== null;
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        return (array) json_decode(decrypt((string) $this->getAttribute('two_factor_recovery_codes')), true);
    }

    public function replaceRecoveryCode(string $code): void
    {
        $codes = array_map(static fn (string $stored): string => $stored === $code ? 'replaced-'.$code : $stored, $this->recoveryCodes());

        $this->forceFill(['two_factor_recovery_codes' => encrypt((string) json_encode($codes))])->save();
    }
}
