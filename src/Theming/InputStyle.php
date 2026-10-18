<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Theming;

/** How form inputs are drawn. */
enum InputStyle: string
{
    /** Bordered inputs. */
    case Outline = 'outline';

    /** Tinted inputs without a border until focused. */
    case Filled = 'filled';

    /** Only a bottom border. */
    case Underline = 'underline';
}
