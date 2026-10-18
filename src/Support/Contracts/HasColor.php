<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Contracts;

use AlfaSix\Alfakit\Support\Enums\Color;

/**
 * An enum with a colour (`primary`, `success`, `danger`, `warning`, `info`, `gray` or a
 * `Color` case), picked up by text columns and entries (best with `badge()`), icon columns
 * and entries, and toggle buttons built from the enum. Array palettes (Filament's
 * `Color::Amber`) are not used by AlfaKit and fall back to no colour.
 */
interface HasColor
{
    /**
     * @return Color|string|array<mixed>|null
     */
    public function getColor(): Color|string|array|null;
}
