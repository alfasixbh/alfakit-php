<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Enums;

/**
 * Where a table row's record actions are rendered.
 *
 * - `BeforeCells`: the first cell of the row, before the selection checkbox.
 * - `BeforeColumns`: after the selection checkbox, before the first column.
 * - `AfterColumns` (default): after the last column.
 * - `AfterCells`: the last cell of the row.
 */
enum RecordActionsPosition: string
{
    case BeforeCells = 'before-cells';
    case BeforeColumns = 'before-columns';
    case AfterColumns = 'after-columns';
    case AfterCells = 'after-cells';
}
