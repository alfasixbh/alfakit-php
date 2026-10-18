<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** How cards, sections and tables are drawn. */
enum SurfaceStyle: string
{
    /** A border and a faint shadow. */
    case Bordered = 'bordered';

    /** No border, a soft shadow. */
    case Elevated = 'elevated';

    /** No border or shadow, a tinted background. */
    case Flat = 'flat';

    /** A hairline ring with a small shadow. */
    case Ring = 'ring';
}
