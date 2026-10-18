<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\PageTable;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;

/** A post whose status is cast to an enum and whose tags list holds enum values. */
class EnumPost extends Post
{
    protected $table = 'posts';

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'status' => PostStatus::class,
            'tags_list' => AsEnumCollection::of(PostStatus::class),
        ];
    }
}
