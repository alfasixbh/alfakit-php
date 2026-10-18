<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Contracts;

use Illuminate\Contracts\Support\Htmlable;

/**
 * A display name for a user, tenant or record. Return `Htmlable` markup (escape dynamic
 * parts with `e()`) to show HTML in the tenant menu; it is sanitised, and places that only
 * take text (titles, `alt`, `aria-label`) use its text.
 */
interface HasName
{
    public function getAlfakitName(): string|Htmlable;
}
