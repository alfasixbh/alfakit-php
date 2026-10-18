<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

class TenantSet
{
    use Dispatchable;

    public function __construct(
        public readonly Model $tenant,
        public readonly ?Authenticatable $user,
    ) {}
}
