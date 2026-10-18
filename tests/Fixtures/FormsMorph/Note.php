<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\FormsMorph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Note extends Model
{
    protected $table = 'morph_notes';

    protected $guarded = [];

    public function notable(): MorphTo
    {
        return $this->morphTo();
    }
}
