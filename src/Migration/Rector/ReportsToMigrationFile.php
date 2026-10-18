<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration\Rector;

use AlfaSix\Alfakit\Migration\MigrationReport;
use PhpParser\Node;

/**
 * Rector runs in its own process: rules append report entries (one JSON object per line) to
 * the file named by `ALFAKIT_MIGRATION_REPORT`, which `RectorRunner` merges into the report.
 */
trait ReportsToMigrationFile
{
    protected function report(string $section, ?Node $node, string $what, string $suggestion = ''): void
    {
        $path = getenv('ALFAKIT_MIGRATION_REPORT');

        if ($path === false || $path === '') {
            return;
        }

        file_put_contents($path, MigrationReport::jsonLine([
            'section' => $section,
            'file' => $this->getFile()->getFilePath(),
            'line' => $node?->getStartLine(),
            'what' => $what,
            'suggestion' => $suggestion,
        ]), FILE_APPEND);
    }
}
