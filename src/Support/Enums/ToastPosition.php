<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Enums;

/**
 * Where toasts appear. `start`/`end` follow the reading direction (left/right in LTR).
 */
enum ToastPosition: string
{
    case TopStart = 'top-start';
    case TopCenter = 'top-center';
    case TopEnd = 'top-end';
    case BottomStart = 'bottom-start';
    case BottomCenter = 'bottom-center';
    case BottomEnd = 'bottom-end';
}
