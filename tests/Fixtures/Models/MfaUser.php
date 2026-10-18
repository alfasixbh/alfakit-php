<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use AlfaSix\Alfakit\Auth\MultiFactor\Concerns\InteractsWithAppAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Concerns\InteractsWithAppAuthenticationRecovery;
use AlfaSix\Alfakit\Auth\MultiFactor\Concerns\InteractsWithEmailAuthentication;
use AlfaSix\Alfakit\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use AlfaSix\Alfakit\Contracts\CanAccessPanel;
use AlfaSix\Alfakit\Contracts\CanBeImpersonated;
use AlfaSix\Alfakit\Contracts\CanImpersonate;
use AlfaSix\Alfakit\Panel;

class MfaUser extends User implements CanAccessPanel, CanBeImpersonated, CanImpersonate, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication
{
    use InteractsWithAppAuthentication;
    use InteractsWithAppAuthenticationRecovery;
    use InteractsWithEmailAuthentication;

    protected $table = 'users';

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->getAttribute('role') !== 'banned';
    }

    public function canImpersonate(): bool
    {
        return in_array($this->getAttribute('role'), ['admin', 'super-admin'], true);
    }

    public function canBeImpersonated(): bool
    {
        return $this->getAttribute('role') !== 'super-admin';
    }
}
