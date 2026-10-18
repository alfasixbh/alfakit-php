<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Contracts;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;

/**
 * An enum with an icon (a Lucide name such as `truck` or a registered alias), picked up by
 * text columns and entries, icon columns and entries, toggle buttons built from the enum and
 * navigation groups. A backed enum returned here is read as its value; `Htmlable` icons
 * cannot be rendered and are ignored.
 */
interface HasIcon
{
    public function getIcon(): string|BackedEnum|Htmlable|null;
}
