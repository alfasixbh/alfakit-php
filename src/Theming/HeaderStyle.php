<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** How the page title area looks. */
enum HeaderStyle: string
{
    /** Title and actions on the page background. */
    case Plain = 'plain';

    /** A tinted hero card behind the title. */
    case Banner = 'banner';

    /** A full-width band across the top of the content. */
    case Band = 'band';

    /** A small title, for dense screens. */
    case Compact = 'compact';
}
