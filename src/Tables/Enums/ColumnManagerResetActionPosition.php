<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Enums;

/**
 * Where the column manager's reset action sits: above the list (`Header`) or below it,
 * next to the apply action of a deferred column manager (`Footer`, the default).
 */
enum ColumnManagerResetActionPosition: string
{
    case Header = 'header';
    case Footer = 'footer';
}
