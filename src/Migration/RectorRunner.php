<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Migration;

use Symfony\Component\Process\Process;

/**
 * Runs the application's own Rector (`vendor/bin/rector`) with the Filament → AlfaKit set
 * shipped in `rector/filament-to-alfakit.php`. The rules append what they could not convert
 * to the file named by `ALFAKIT_MIGRATION_REPORT`, which is merged into the report.
 */
final class RectorRunner
{
    public const CONFIG = __DIR__.'/../../rector/filament-to-alfakit.php';

    public function __construct(private readonly string $basePath, private readonly ?string $binary = null) {}

    /**
     * The Rector binary to use: the explicit path, else the app's `vendor/bin/rector`, else null.
     */
    public function binary(): ?string
    {
        if ($this->binary !== null) {
            return is_file($this->binary) ? $this->binary : null;
        }

        $local = $this->basePath.'/vendor/bin/rector';

        return is_file($local) ? $local : null;
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    /**
     * Rewrite the given files in place. Returns the process output; entries written by the
     * rules are merged into `$report`.
     *
     * @param  array<int, string>  $files
     */
    public function run(array $files, MigrationReport $report): string
    {
        $binary = $this->binary();

        if ($binary === null || $files === []) {
            return '';
        }

        $reportFile = tempnam(sys_get_temp_dir(), 'alfakit-migration-');

        if ($reportFile === false) {
            $reportFile = sys_get_temp_dir().'/alfakit-migration-'.uniqid().'.jsonl';
        }

        file_put_contents($reportFile, '');

        $command = [PHP_BINARY, $binary, 'process', ...$files, '--config', realpath(self::CONFIG) ?: self::CONFIG, '--no-progress-bar', '--no-diffs', '--clear-cache', '--no-ansi'];

        $process = new Process($command, $this->basePath, [...getenv(), 'ALFAKIT_MIGRATION_REPORT' => $reportFile], timeout: 900);
        $process->run();

        $report->mergeJsonLines((string) file_get_contents($reportFile));
        @unlink($reportFile);

        $output = $process->getOutput().$process->getErrorOutput();

        if (! $process->isSuccessful()) {
            $report->add(MigrationReport::SECTION_NOTES, 'vendor/bin/rector', null, 'Rector exited with code '.$process->getExitCode(), 'The built-in rewriter completed the remaining files. Output: '.trim(mb_strimwidth($output, 0, 500, '…')));
        }

        return $output;
    }
}
