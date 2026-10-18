<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Concerns;

use AlfaSix\Alfakit\Actions\Exports\Testing\ExportsFake;

/**
 * `ExportAction::fake()` / `ExportBulkAction::fake()` for tests: record the exports the
 * action starts instead of running their jobs (see `ExportsFake`).
 */
trait CanFakeExports
{
    public static function fake(): ExportsFake
    {
        return ExportsFake::fake();
    }
}
