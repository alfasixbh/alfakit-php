<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Pivot;

use Illuminate\Database\Eloquent\Model;

class Lesson extends Model
{
    protected $table = 'pivot_lessons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
