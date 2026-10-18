<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * The side a slide-over enters from. Logical, so it follows the reading direction: `End`
 * (the default) is the right edge in left-to-right panels and the left edge in RTL ones.
 */
enum SlideOverPosition: string
{
    case Start = 'start';
    case End = 'end';
}
