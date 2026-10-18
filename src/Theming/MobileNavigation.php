<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/**
 * How the panel behaves below the `md` breakpoint.
 */
enum MobileNavigation: string
{
    /** The sidebar (or top navigation) opens as a drawer; tables and modals keep their desktop shape. */
    case Drawer = 'drawer';

    /**
     * The mobile preset: a bottom navigation bar with the top-level items (the rest in a
     * sheet), tables as stacked cards, slide-over modals, 44px touch targets on primary
     * controls and sticky form actions.
     */
    case Bottom = 'bottom';
}
