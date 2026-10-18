<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Contracts;

use Illuminate\Contracts\Support\Htmlable;

/**
 * An enum with a description, shown under each option of `Radio` and `CheckboxList`
 * built with `options(Status::class)`.
 */
interface HasDescription
{
    public function getDescription(): string|Htmlable|null;
}
