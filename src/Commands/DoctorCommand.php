<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Commands;

use AlfaSix\Alfakit\Support\Doctor\Doctor;
use AlfaSix\Alfakit\Support\Doctor\DoctorCheck;
use AlfaSix\Alfakit\Support\Doctor\DoctorStatus;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'alfakit:doctor', description: 'Check the AlfaKit installation and explain how to fix what is wrong')]
class DoctorCommand extends Command
{
    protected $signature = 'alfakit:doctor
        {--json : Print the report as JSON (for CI)}
        {--strict : Fail on warnings too}';

    public function handle(Doctor $doctor): int
    {
        $checks = $doctor->run();
        $counts = ['ok' => 0, 'warning' => 0, 'error' => 0, 'skipped' => 0];

        foreach ($checks as $check) {
            $counts[$check->status->value]++;
        }

        $failed = $counts['error'] > 0 || ($this->option('strict') && $counts['warning'] > 0);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'passed' => ! $failed,
                'summary' => $counts,
                'checks' => array_map(static fn (DoctorCheck $check): array => $check->toArray(), $checks),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->table(['', 'Group', 'Check', 'Result'], array_map(static fn (DoctorCheck $check): array => [
            self::styled($check->status),
            $check->group,
            $check->label,
            $check->message,
        ], $checks));

        $fixes = array_filter($checks, static fn (DoctorCheck $check): bool => $check->fix !== null && in_array($check->status, [DoctorStatus::Warning, DoctorStatus::Error], true));

        if ($fixes !== []) {
            $this->newLine();
            $this->line('<options=bold>How to fix</>');

            foreach ($fixes as $check) {
                $this->line(sprintf('  %s %s › %s: %s', self::styled($check->status), $check->group, $check->label, $check->fix));
            }
        }

        $this->newLine();
        $summary = sprintf('%d passed, %d warnings, %d errors', $counts['ok'], $counts['warning'], $counts['error']);

        if ($failed) {
            $this->components->error($summary);
        } elseif ($counts['warning'] > 0) {
            $this->components->warn($summary);
        } else {
            $this->components->info($summary);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    protected static function styled(DoctorStatus $status): string
    {
        return match ($status) {
            DoctorStatus::Ok => '<fg=green>'.$status->symbol().'</>',
            DoctorStatus::Warning => '<fg=yellow>'.$status->symbol().'</>',
            DoctorStatus::Error => '<fg=red>'.$status->symbol().'</>',
            DoctorStatus::Skipped => '<fg=gray>'.$status->symbol().'</>',
        };
    }
}
