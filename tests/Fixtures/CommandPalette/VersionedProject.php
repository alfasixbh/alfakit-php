<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tests\Fixtures\CommandPalette;

use AlfaSix\Alfakit\Tests\Fixtures\Models\Project;
use AlfaSix\Alfakit\Versions\HasRecordVersions;

class VersionedProject extends Project
{
    use HasRecordVersions;

    protected $table = 'projects';
}
