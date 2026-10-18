<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Parity3\Tenancy;

use AlfaSix\Alfakit\Contracts\HasCurrentTenantLabel;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;

class P3LabelledTeam extends Team implements HasCurrentTenantLabel
{
    protected $table = 'teams';

    public function getCurrentTenantLabel(): string
    {
        return 'Active team';
    }
}
