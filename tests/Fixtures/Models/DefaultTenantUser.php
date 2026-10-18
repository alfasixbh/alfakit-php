<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Contracts\HasDefaultTenant;
use AlfaSix\Alfakit\Panel;
use Illuminate\Database\Eloquent\Model;

class DefaultTenantUser extends TenantUser implements HasDefaultTenant
{
    public static ?string $defaultSlug = 'beta';

    public function getDefaultTenant(Panel $panel): ?Model
    {
        return Team::query()->where('slug', static::$defaultSlug)->first();
    }
}
