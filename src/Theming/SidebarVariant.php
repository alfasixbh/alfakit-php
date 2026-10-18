<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

enum SidebarVariant: string
{
    /** The page sits in a rounded card next to the sidebar. */
    case Inset = 'inset';

    /** The sidebar is a floating card with a gap around it. */
    case Floating = 'floating';

    /** A classic full-height sidebar separated by a border. */
    case Flush = 'flush';
}
