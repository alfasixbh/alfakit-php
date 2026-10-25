<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Tables\Columns\Concerns\CanUpdateState;
use BackedEnum;
use Closure;

class ToggleColumn extends Column
{
    use CanUpdateState;

    protected string|Closure|null $onColor = null;

    protected string|Closure|null $offColor = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $onIcon = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $offIcon = null;

    public function onColor(string|Closure|null $color): static
    {
        $this->onColor = $color;

        return $this;
    }

    public function offColor(string|Closure|null $color): static
    {
        $this->offColor = $color;

        return $this;
    }

    public function onIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->onIcon = $icon;

        return $this;
    }

    public function offIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->offIcon = $icon;

        return $this;
    }

    /**
     * The icon shown when on, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getOnIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->onIcon));
    }

    /**
     * The icon shown when off, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getOffIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->offIcon));
    }

    protected function mutateStateForUpdate(mixed $state): mixed
    {
        return (bool) $state;
    }

    protected function getFormattedState(): mixed
    {
        return (bool) $this->getState();
    }

    protected function getSchemaProps(): array
    {
        return [
            'editable' => true,
            'onColor' => $this->evaluate($this->onColor),
            'offColor' => $this->evaluate($this->offColor),
            'onIcon' => $this->getOnIcon(),
            'offIcon' => $this->getOffIcon(),
        ];
    }

    protected function getCellProps(): array
    {
        return ['disabled' => $this->isDisabledForRecord() ?: null];
    }
}
