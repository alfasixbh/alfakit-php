<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Support\Enums\Alignment;
use Closure;

/**
 * Slots around a field's validation error, shown only while the field has an error. They
 * take the same content as the label and content slots (see `HasContentSlots`).
 */
trait HasErrorMessageSlots
{
    public function aboveErrorMessage(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('aboveErrorMessage', $content, $alignment);
    }

    public function belowErrorMessage(mixed $content, Alignment|string|Closure|null $alignment = null): static
    {
        return $this->setSlotContent('belowErrorMessage', $content, $alignment);
    }

    /**
     * @return array<int, string>
     */
    public function getContentSlotNames(): array
    {
        return ['aboveLabel', 'beforeLabel', 'afterLabel', 'belowLabel', 'aboveContent', 'beforeContent', 'afterContent', 'belowContent', 'aboveErrorMessage', 'belowErrorMessage'];
    }
}
