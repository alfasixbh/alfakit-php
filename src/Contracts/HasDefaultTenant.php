<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

use AlfaSix\Alfakit\Panel;
use Illuminate\Database\Eloquent\Model;

interface HasDefaultTenant
{
    public function getDefaultTenant(Panel $panel): ?Model;
}
