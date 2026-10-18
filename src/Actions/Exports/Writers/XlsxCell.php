<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Writers;

/**
 * One XLSX cell: a value and an optional style that overrides the row's.
 */
final class XlsxCell
{
    public function __construct(
        public readonly string|int|float|bool|null $value,
        public readonly ?XlsxStyle $style = null,
    ) {}

    public static function fromValue(string|int|float|bool|null $value, ?XlsxStyle $style = null): self
    {
        return new self($value, $style);
    }
}
