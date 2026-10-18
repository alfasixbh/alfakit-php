<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Tables\Columns\Concerns\CanUpdateState;
use BackedEnum;
use Closure;

class TextInputColumn extends Column
{
    use CanUpdateState;

    protected string|Closure $inputType = 'text';

    protected string|Closure|null $placeholder = null;

    protected string|Closure|null $inputMode = null;

    protected int|float|string|Closure|null $step = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $prefixIcon = null;

    protected string|Closure|null $prefixIconColor = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $suffixIcon = null;

    protected string|Closure|null $suffixIconColor = null;

    protected string|Closure|null $prefix = null;

    protected string|Closure|null $suffix = null;

    /**
     * Text before the input (`'$'`, `'https://'`); a closure receives the `$record`.
     */
    public function prefix(string|Closure|null $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Text after the input (`'kg'`, `'%'`); a closure receives the `$record`.
     */
    public function suffix(string|Closure|null $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function prefixIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon, string|Closure|null $color = null): static
    {
        $this->prefixIcon = $icon;

        if ($color !== null) {
            $this->prefixIconColor = $color;
        }

        return $this;
    }

    public function prefixIconColor(string|Closure|null $color): static
    {
        $this->prefixIconColor = $color;

        return $this;
    }

    public function suffixIcon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon, string|Closure|null $color = null): static
    {
        $this->suffixIcon = $icon;

        if ($color !== null) {
            $this->suffixIconColor = $color;
        }

        return $this;
    }

    public function suffixIconColor(string|Closure|null $color): static
    {
        $this->suffixIconColor = $color;

        return $this;
    }

    public function type(string|Closure $type): static
    {
        $this->inputType = $type;

        return $this;
    }

    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function inputMode(string|Closure|null $mode): static
    {
        $this->inputMode = $mode;

        return $this;
    }

    public function step(int|float|string|Closure|null $step): static
    {
        $this->step = $step;

        return $this;
    }

    protected function mutateStateForUpdate(mixed $state): mixed
    {
        if ($state === '') {
            return null;
        }

        return $this->evaluate($this->inputType) === 'number' && is_numeric($state) ? $state + 0 : $state;
    }

    protected function getSchemaProps(): array
    {
        return [
            'editable' => true,
            'inputType' => $this->evaluate($this->inputType),
            'placeholder' => $this->evaluate($this->placeholder),
            'inputMode' => $this->evaluate($this->inputMode),
            'step' => $this->evaluate($this->step),
            'prefixIcon' => Icons::resolve($this->evaluate($this->prefixIcon)),
            'prefixIconColor' => $this->evaluate($this->prefixIconColor),
            'suffixIcon' => Icons::resolve($this->evaluate($this->suffixIcon)),
            'suffixIconColor' => $this->evaluate($this->suffixIconColor),
        ];
    }

    protected function getCellProps(): array
    {
        $prefix = $this->evaluate($this->prefix);
        $suffix = $this->evaluate($this->suffix);

        return [
            'disabled' => $this->isDisabled() ?: null,
            'prefix' => $prefix === null || $prefix === '' ? null : (string) $prefix,
            'suffix' => $suffix === null || $suffix === '' ? null : (string) $suffix,
        ];
    }
}
