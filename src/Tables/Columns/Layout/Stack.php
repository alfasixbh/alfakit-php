<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Layout;

use AlfaSix\Alfakit\Support\Enums\Alignment;
use Closure;

/**
 * Columns stacked vertically.
 *
 * ```php
 * Stack::make([
 *     TextColumn::make('name')->weight(FontWeight::Bold),
 *     TextColumn::make('email'),
 * ])->space(1)
 * ```
 */
class Stack extends Component
{
    protected int|Closure|null $space = null;

    protected Alignment|Closure|null $alignment = null;

    /**
     * Gap between the stacked columns: 1 (small), 2 or 3 (large).
     */
    public function space(int|Closure|null $space): static
    {
        $this->space = $space;

        return $this;
    }

    public function alignment(Alignment|Closure|null $alignment): static
    {
        $this->alignment = $alignment;

        return $this;
    }

    public function alignStart(): static
    {
        return $this->alignment(Alignment::Start);
    }

    public function alignCenter(): static
    {
        return $this->alignment(Alignment::Center);
    }

    public function alignEnd(): static
    {
        return $this->alignment(Alignment::End);
    }

    protected function getSchemaProps(): array
    {
        $space = $this->evaluate($this->space);

        return [
            'space' => in_array($space, [1, 2, 3], true) ? $space : null,
            'alignment' => $this->evaluate($this->alignment)?->value,
        ];
    }
}
