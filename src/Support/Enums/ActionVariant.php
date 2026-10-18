<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

enum ActionVariant: string
{
    case Button = 'button';
    case Outline = 'outline';
    case Ghost = 'ghost';
    case Link = 'link';
    case IconButton = 'icon-button';
}
