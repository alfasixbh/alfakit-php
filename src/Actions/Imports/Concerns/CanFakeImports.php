<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Imports\Concerns;

use AlfaSix\Alfakit\Actions\Imports\Testing\ImportsFake;

/**
 * `ImportAction::fake()` for tests: record the imports the action starts instead of
 * running their jobs (see `ImportsFake`).
 */
trait CanFakeImports
{
    public static function fake(): ImportsFake
    {
        return ImportsFake::fake();
    }
}
