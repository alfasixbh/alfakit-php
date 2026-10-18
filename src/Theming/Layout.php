<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** Overall page structure. */
enum Layout: string
{
    /** Navigation in a sidebar. */
    case Sidebar = 'sidebar';

    /** Navigation across the top; no sidebar. */
    case Topbar = 'topbar';

    /** A slim icon rail that expands on demand. */
    case Rail = 'rail';
}
