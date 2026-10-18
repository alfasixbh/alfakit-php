<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Contracts;

use Illuminate\Contracts\Support\Htmlable;

/**
 * An enum (or any object) with a human-readable label. Used by `options(Status::class)` on
 * selects, radios, checkbox lists, toggle buttons, select columns and select filters, by
 * text columns and entries showing the enum, by table group titles and navigation groups.
 */
interface HasLabel
{
    public function getLabel(): string|Htmlable|null;
}
