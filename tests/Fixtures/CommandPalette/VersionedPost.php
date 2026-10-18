<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\CommandPalette;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Post;
use AlfaSix\Alfakit\Versions\HasRecordVersions;

class VersionedPost extends Post
{
    use HasRecordVersions;

    protected $table = 'posts';

    /** @var array<string> */
    protected array $versionExclude = ['rating'];

    protected int $maxVersions = 5;
}
