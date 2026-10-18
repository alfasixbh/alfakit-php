<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use Closure;

/**
 * Fields fused into one control: a shared border and no gaps between them, e.g. a
 * country select next to a phone number. Each field gets its own row by default;
 * `columns()` lays them out side by side (from `lg` up for an integer) and
 * `columnSpan()` on a child sets its width. The children's labels become screen-reader
 * text (use placeholders) and their helper texts are not shown; validation errors are
 * listed below the group.
 */
class FusedGroup extends Component
{
    /**
     * @param  array<Component>|Closure  $schema
     */
    final public function __construct(array|Closure $schema = [])
    {
        $this->schema($schema);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }
}
