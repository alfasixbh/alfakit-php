<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Support\Enums\IconPosition;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;

trait HasIcon
{
    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected IconPosition|Closure|null $iconPosition = null;

    /**
     * Icon names follow the Lucide naming scheme (e.g. `users`, `shopping-cart`); icon
     * aliases registered with `Icons::register()` are resolved when serialised.
     */
    public function icon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function iconPosition(IconPosition|Closure|null $position): static
    {
        $this->iconPosition = $position;

        return $this;
    }

    /**
     * The icon to render, with aliases (`alfakit::actions.delete`) resolved through `Icons`.
     */
    public function getIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->icon));
    }

    public function getIconPosition(): IconPosition
    {
        return $this->evaluate($this->iconPosition) ?? IconPosition::Before;
    }
}
