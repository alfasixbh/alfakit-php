<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** How navigation items look. */
enum NavStyle: string
{
    /** Subtle background on the active item. */
    case Soft = 'soft';

    /** Rounded pills with a tinted active item. */
    case Pill = 'pill';

    /** An accent bar marks the active item. */
    case Bar = 'bar';

    /** Tabs with an underline (top navigation). */
    case Underline = 'underline';
}
