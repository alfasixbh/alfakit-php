<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components;

use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;

class Toggle extends Field
{
    use Concerns\CanFixIndistinctState;

    protected bool|Closure $isInline = true;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $onIcon = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $offIcon = null;

    protected string|Closure|null $onColor = null;

    protected string|Closure|null $offColor = null;

    protected bool|Closure $isAccepted = false;

    protected bool|Closure $isDeclined = false;

    public function inline(bool|Closure $condition = true): static
    {
        $this->isInline = $condition;

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

    public function accepted(bool|Closure $condition = true): static
    {
        $this->isAccepted = $condition;

        return $this;
    }

    /**
     * Must be off.
     */
    public function declined(bool|Closure $condition = true): static
    {
        $this->isDeclined = $condition;

        return $this;
    }

    protected function getDefaultStateWhenEmpty(): mixed
    {
        return false;
    }

    protected function getTypeValidationRules(): array
    {
        $rules = ['boolean'];

        if ($this->evaluate($this->isAccepted)) {
            $rules[] = 'accepted';
        }

        if ($this->evaluate($this->isDeclined)) {
            $rules[] = 'declined';
        }

        return $rules;
    }

    protected function mutateDehydratedState(mixed $state): mixed
    {
        return (bool) $state;
    }

    protected function getSchemaProps(): array
    {
        return [
            'inline' => (bool) $this->evaluate($this->isInline),
            'onIcon' => $this->getOnIcon(),
            'offIcon' => $this->getOffIcon(),
            'onColor' => $this->evaluate($this->onColor),
            'offColor' => $this->evaluate($this->offColor),
        ];
    }

    /** Changes once per choice: `live()` refreshes without waiting. */
    public function getDefaultLiveDebounce(): int
    {
        return 0;
    }
}
