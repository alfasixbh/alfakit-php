<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A comment as the quick start's migration makes it: a post and a rich text body, nothing
 * that names it (`remarks` is created by the test that uses it).
 */
class Remark extends Model
{
    protected $guarded = [];
}
