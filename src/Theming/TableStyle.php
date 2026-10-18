<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** How tables are drawn. */
enum TableStyle: string
{
    /** Row dividers. */
    case Lined = 'lined';

    /** Alternating row backgrounds. */
    case Striped = 'striped';

    /** No row dividers, airy rows. */
    case Minimal = 'minimal';
}
