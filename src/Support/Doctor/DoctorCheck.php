<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Doctor;

/**
 * One line of the `alfakit:doctor` report: what was checked, what was found and, for
 * warnings and errors, how to fix it.
 */
final class DoctorCheck
{
    public function __construct(
        public readonly string $group,
        public readonly string $label,
        public readonly DoctorStatus $status,
        public readonly string $message,
        public readonly ?string $fix = null,
    ) {}

    public static function ok(string $group, string $label, string $message): self
    {
        return new self($group, $label, DoctorStatus::Ok, $message);
    }

    public static function warning(string $group, string $label, string $message, ?string $fix = null): self
    {
        return new self($group, $label, DoctorStatus::Warning, $message, $fix);
    }

    public static function error(string $group, string $label, string $message, ?string $fix = null): self
    {
        return new self($group, $label, DoctorStatus::Error, $message, $fix);
    }

    public static function skipped(string $group, string $label, string $message): self
    {
        return new self($group, $label, DoctorStatus::Skipped, $message);
    }

    /**
     * @return array{group: string, label: string, status: string, message: string, fix: string|null}
     */
    public function toArray(): array
    {
        return [
            'group' => $this->group,
            'label' => $this->label,
            'status' => $this->status->value,
            'message' => $this->message,
            'fix' => $this->fix,
        ];
    }
}
