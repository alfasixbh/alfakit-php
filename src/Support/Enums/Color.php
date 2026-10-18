<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * Semantic colours understood by every Alfakit renderer.
 *
 * Renderers map these onto the shadcn CSS variables (`--primary`, `--destructive`, …)
 * plus the extra `--success`, `--warning` and `--info` tokens shipped in Alfakit's stylesheet.
 */
enum Color: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';
    case Gray = 'gray';
}
