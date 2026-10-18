<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Exports\Writers;

/**
 * One XLSX row: cells plus a style for the whole row (each cell's own style wins).
 */
final class XlsxRow
{
    /**
     * @param  array<int, XlsxCell>  $cells
     */
    public function __construct(
        public readonly array $cells,
        public readonly ?XlsxStyle $style = null,
    ) {}

    /**
     * @param  array<int, string|int|float|bool|null>  $values
     */
    public static function fromValues(array $values, ?XlsxStyle $style = null): self
    {
        return new self(array_map(static fn (mixed $value): XlsxCell => new XlsxCell($value), array_values($values)), $style);
    }

    /**
     * @param  array<int, XlsxCell>  $cells
     */
    public static function fromCells(array $cells, ?XlsxStyle $style = null): self
    {
        return new self(array_values($cells), $style);
    }
}
