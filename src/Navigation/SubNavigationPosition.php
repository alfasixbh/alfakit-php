<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation;

/**
 * Where a page's sub-navigation (cluster pages, record pages) is rendered: in a column at
 * the start or end of the content, or as tabs above it.
 */
enum SubNavigationPosition: string
{
    case Start = 'start';
    case End = 'end';
    case Top = 'top';
}
