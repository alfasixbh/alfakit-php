<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;

trait HasAffixes
{
    protected string|Closure|null $prefixLabel = null;

    protected string|Closure|null $suffixLabel = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $prefixIcon = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $suffixIcon = null;

    protected string|Closure|null $prefixIconColor = null;

    protected string|Closure|null $suffixIconColor = null;

    public function prefix(string|Closure|null $label): static
    {
        $this->prefixLabel = $label;

        return $this;
    }

    public function suffix(string|Closure|null $label): static
    {
        $this->suffixLabel = $label;

        return $this;
    }

    public function prefixIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->prefixIcon = $icon;

        return $this;
    }

    public function suffixIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->suffixIcon = $icon;

        return $this;
    }

    /**
     * Colour of the prefix icon (`primary`, `danger`, `success`…).
     */
    public function prefixIconColor(string|Closure|null $color): static
    {
        $this->prefixIconColor = $color;

        return $this;
    }

    public function suffixIconColor(string|Closure|null $color): static
    {
        $this->suffixIconColor = $color;

        return $this;
    }

    /**
     * The prefix icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getPrefixIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->prefixIcon));
    }

    /**
     * The suffix icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getSuffixIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->suffixIcon));
    }

    /**
     * @return array<string, string|null>
     */
    public function getAffixes(): array
    {
        return [
            'prefix' => $this->evaluate($this->prefixLabel),
            'suffix' => $this->evaluate($this->suffixLabel),
            'prefixIcon' => $this->getPrefixIcon(),
            'suffixIcon' => $this->getSuffixIcon(),
            'prefixIconColor' => $this->evaluate($this->prefixIconColor),
            'suffixIconColor' => $this->evaluate($this->suffixIconColor),
        ];
    }
}
