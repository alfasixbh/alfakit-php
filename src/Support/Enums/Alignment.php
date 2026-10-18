<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

enum Alignment: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
    case Between = 'between';
    case Justify = 'justify';
}
