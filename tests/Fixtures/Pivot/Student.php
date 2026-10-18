<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot;

use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    protected $table = 'pivot_students';

    protected $guarded = [];
}
