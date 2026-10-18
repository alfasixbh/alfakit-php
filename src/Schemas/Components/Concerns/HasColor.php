<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Support\Enums\Color;
use Closure;

trait HasColor
{
    protected Color|string|Closure|null $color = null;

    public function color(Color|string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getColor(): ?string
    {
        $color = $this->evaluate($this->color);

        return $color instanceof Color ? $color->value : $color;
    }
}
