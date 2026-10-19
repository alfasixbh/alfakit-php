<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A comment whose relationship has no return type, as many apps declare them.
 */
class UntypedComment extends Model
{
    protected $table = 'comments';

    protected $guarded = [];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
