<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

/**
 * The Filament v3 name for `Flex`, serialised (and rendered) as a `Flex`.
 */
class Split extends Flex
{
    public function getType(): string
    {
        return $this->type ?? 'Flex';
    }
}
