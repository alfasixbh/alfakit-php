<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose table (created by its test) has literal column defaults, for the
 * generators' `->default()` output.
 */
class DefaultsGadget extends Model
{
    protected $table = 'wizard_defaults_gadgets';

    protected $guarded = [];
}
