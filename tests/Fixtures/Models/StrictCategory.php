<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model on the categories table that only allows mass assignment of `name`, like a
 * model made with `make:model` and a partial `$fillable`.
 */
class StrictCategory extends Model
{
    protected $table = 'categories';

    protected $fillable = ['name'];
}
