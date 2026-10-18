<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

enum Density: string
{
    case Comfortable = 'comfortable';

    /** Tighter tables, cards and form spacing for data-heavy panels. */
    case Compact = 'compact';
}
