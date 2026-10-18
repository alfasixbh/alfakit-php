<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

use AlfaSix\Alfakit\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Implemented by the User model when a panel uses multi-tenancy.
 */
interface HasTenants
{
    /**
     * @return array<Model>|Collection<int, Model>
     */
    public function getTenants(Panel $panel): array|Collection;

    public function canAccessTenant(Model $tenant): bool;
}
