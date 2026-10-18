<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Enums;

/**
 * Where a table's filters form is rendered: `$table->filtersLayout(FiltersLayout::Modal)`.
 *
 * - `Dropdown` (default): a popover opened from the toolbar.
 * - `AboveContent`, `AboveContentCollapsible`, `BelowContent`: inline panels.
 * - `Modal`: a dialog opened from the toolbar.
 * - `BeforeContent`, `AfterContent` (+ `Collapsible`): a sidebar at the start or end of
 *   the table (stacked above it on small screens).
 */
enum FiltersLayout: string
{
    case Dropdown = 'dropdown';
    case AboveContent = 'above-content';
    case AboveContentCollapsible = 'above-content-collapsible';
    case BelowContent = 'below-content';
    case Modal = 'modal';
    case BeforeContent = 'before-content';
    case BeforeContentCollapsible = 'before-content-collapsible';
    case AfterContent = 'after-content';
    case AfterContentCollapsible = 'after-content-collapsible';
}
