<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Tenancy\Check;

use Illuminate\Database\Eloquent\Model;

/** No ownership relationship: a scoped resource for it cannot work. */
class Note extends Model
{
    protected $guarded = [];
}
