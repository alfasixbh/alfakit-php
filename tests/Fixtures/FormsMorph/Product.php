<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsMorph;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $table = 'morph_products';

    protected $guarded = [];
}
