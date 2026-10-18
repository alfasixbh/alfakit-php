<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Forms\Components\Concerns;

use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

trait HasHelperText
{
    protected string|Htmlable|Closure|null $helperText = null;

    protected string|Htmlable|Closure|null $hint = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $hintIcon = null;

    protected string|Closure|null $hintColor = null;

    public function helperText(string|Htmlable|Closure|null $text): static
    {
        $this->helperText = $text;

        return $this;
    }

    public function hint(string|Htmlable|Closure|null $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    public function hintIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->hintIcon = $icon;

        return $this;
    }

    public function hintColor(string|Closure|null $color): static
    {
        $this->hintColor = $color;

        return $this;
    }

    public function getHelperText(): ?string
    {
        return \AlfaSix\Alfakit\Support\Html::from($this->evaluate($this->helperText));
    }

    public function getHint(): ?string
    {
        return \AlfaSix\Alfakit\Support\Html::from($this->evaluate($this->hint));
    }

    public function getHintIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->hintIcon));
    }

    public function getHintColor(): ?string
    {
        return $this->evaluate($this->hintColor);
    }
}
