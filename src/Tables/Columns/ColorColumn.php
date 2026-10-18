<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Support\Colors\CssColor;
use AlfaSix\Alfakit\Support\Concerns\HasTextStyles;
use AlfaSix\Alfakit\Support\Serializable;

class ColorColumn extends Column
{
    use HasTextStyles;

    protected function getSchemaProps(): array
    {
        return $this->getTextStyleSchemaProps();
    }

    /**
     * Only plain CSS colours reach the renderer (`Str::sanitizeCssColor()`); anything else
     * (`url()`, `var()`, extra declarations) becomes null and shows the placeholder.
     */
    protected function getFormattedState(): mixed
    {
        $state = Serializable::value($this->getState());

        if (is_array($state)) {
            return array_values(array_filter(array_map(CssColor::sanitize(...), $state), static fn (?string $color): bool => $color !== null));
        }

        return $state === null ? null : CssColor::sanitize($state);
    }
}
