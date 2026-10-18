<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Doctor;

/**
 * Outcome of one `alfakit:doctor` check.
 */
enum DoctorStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Skipped = 'skipped';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '✓',
            self::Warning => '!',
            self::Error => '✗',
            self::Skipped => '-',
        };
    }
}
