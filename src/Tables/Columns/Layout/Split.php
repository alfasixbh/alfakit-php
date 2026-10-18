<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Layout;

use Closure;

/**
 * Columns side by side. Below the `from()` breakpoint they stack, so a record stays
 * readable on small screens.
 *
 * ```php
 * Split::make([
 *     ImageColumn::make('avatar')->circular()->grow(false),
 *     TextColumn::make('name')->searchable()->sortable(),
 *     TextColumn::make('email'),
 * ])->from('md')
 * ```
 */
class Split extends Component
{
    protected string|Closure|null $from = null;

    /**
     * Breakpoint (`sm`, `md`, `lg`, `xl`, `2xl`) from which the columns are placed side
     * by side. Without it they are always side by side.
     */
    public function from(string|Closure|null $breakpoint): static
    {
        $this->from = $breakpoint;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        return [
            'from' => self::breakpoint($this->evaluate($this->from)),
        ];
    }
}
