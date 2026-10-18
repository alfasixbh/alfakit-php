<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Enums;

/**
 * Where the filters form's reset action sits: above the fields (`Header`) or below them,
 * next to the apply action (`Footer`, the default).
 */
enum FiltersResetActionPosition: string
{
    case Header = 'header';
    case Footer = 'footer';
}
