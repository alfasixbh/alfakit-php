<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Enums;

/**
 * How the column manager opens: `$table->columnManagerLayout(ColumnManagerLayout::Modal)`.
 *
 * - `Dropdown` (default): a popover under the toolbar button.
 * - `Modal`: a dialog.
 */
enum ColumnManagerLayout: string
{
    case Dropdown = 'dropdown';
    case Modal = 'modal';
}
