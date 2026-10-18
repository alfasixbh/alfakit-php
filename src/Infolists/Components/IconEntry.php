<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Support\Concerns\HasEnumState;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;

class IconEntry extends Entry
{
    use HasColor;
    use HasEnumState;
    use HasIcon;

    protected bool|Closure $isBoolean = false;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $trueIcon = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $falseIcon = null;

    protected string|Closure|null $trueColor = null;

    protected string|Closure|null $falseColor = null;

    protected Size|string|Closure|null $size = null;

    public function boolean(bool|Closure $condition = true): static
    {
        $this->isBoolean = $condition;

        return $this;
    }

    public function trueIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->trueIcon = $icon;

        return $this;
    }

    public function falseIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->falseIcon = $icon;

        return $this;
    }

    public function trueColor(string|Closure|null $color): static
    {
        $this->trueColor = $color;

        return $this;
    }

    public function falseColor(string|Closure|null $color): static
    {
        $this->falseColor = $color;

        return $this;
    }

    public function size(Size|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    protected function getFormattedState(): mixed
    {
        $state = $this->getState();

        return $this->evaluate($this->isBoolean) ? (bool) $state : $state;
    }

    protected function getSchemaProps(): array
    {
        $state = $this->getState();
        $size = $this->evaluate($this->size);

        if ($this->evaluate($this->isBoolean)) {
            $icon = $state ? ($this->evaluate($this->trueIcon) ?? 'circle-check') : ($this->evaluate($this->falseIcon) ?? 'circle-x');
            $color = $state ? ($this->evaluate($this->trueColor) ?? 'success') : ($this->evaluate($this->falseColor) ?? 'danger');
        } else {
            // An enum state implementing `HasIcon` / `HasColor` supplies its own.
            $case = $this->resolveEnumCase($state);
            $icon = $this->getIcon() ?? ($case !== null ? EnumState::icon($case) : (is_string($state) ? $state : null));
            $color = $this->getColor() ?? ($case !== null ? EnumState::color($case) : null);
        }

        // True/false icons and icons named by the state itself resolve aliases too.
        return ['icon' => Icons::resolve($icon), 'color' => $color, 'size' => $size instanceof Size ? $size->value : $size];
    }
}
