<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check;

use AlfaSix\Alfakit\Tenancy\SharedAcrossTenants;
use Illuminate\Database\Eloquent\Model;

#[SharedAcrossTenants]
class Currency extends Model
{
    protected $guarded = [];
}
