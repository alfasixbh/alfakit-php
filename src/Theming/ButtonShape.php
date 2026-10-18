<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** Corner shape of buttons. */
enum ButtonShape: string
{
    /** Follows the theme radius. */
    case Rounded = 'rounded';

    /** Fully rounded. */
    case Pill = 'pill';

    /** Nearly square corners. */
    case Square = 'square';
}
