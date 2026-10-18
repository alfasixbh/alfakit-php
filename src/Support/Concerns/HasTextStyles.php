<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;

trait HasTextStyles
{
    protected string|Closure|null $size = null;

    protected string|Closure|null $weight = null;

    protected string|Closure|null $fontFamily = null;

    protected int|Closure|null $lineClamp = null;

    protected bool|Closure $isBadge = false;

    protected bool|Closure $isCopyable = false;

    protected string|Closure|null $copyMessage = null;

    protected int|Closure|null $copyMessageDuration = null;

    protected bool|Closure $isWrapped = false;

    public function size(string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function weight(string|Closure|null $weight): static
    {
        $this->weight = $weight;

        return $this;
    }

    public function fontFamily(string|Closure|null $family): static
    {
        $this->fontFamily = $family;

        return $this;
    }

    public function lineClamp(int|Closure|null $lines): static
    {
        $this->lineClamp = $lines;

        return $this;
    }

    public function badge(bool|Closure $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    public function copyable(bool|Closure $condition = true, string|Closure|null $message = null, int|Closure|null $duration = null): static
    {
        $this->isCopyable = $condition;

        if ($message !== null) {
            $this->copyMessage = $message;
        }

        if ($duration !== null) {
            $this->copyMessageDuration = $duration;
        }

        return $this;
    }

    /**
     * The confirmation shown after copying (default: the translated "Copied").
     */
    public function copyMessage(string|Closure|null $message): static
    {
        $this->copyMessage = $message;

        return $this;
    }

    /**
     * How long, in milliseconds, the copy confirmation stays (default 1500).
     */
    public function copyMessageDuration(int|Closure|null $milliseconds): static
    {
        $this->copyMessageDuration = $milliseconds;

        return $this;
    }

    public function wrap(bool|Closure $condition = true): static
    {
        $this->isWrapped = $condition;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getTextStyleSchemaProps(): array
    {
        return [
            'size' => $this->evaluate($this->size),
            'weight' => $this->evaluate($this->weight),
            'fontFamily' => $this->evaluate($this->fontFamily),
            'lineClamp' => $this->evaluate($this->lineClamp),
            'badge' => $this->evaluate($this->isBadge) ?: null,
            'copyable' => $this->evaluate($this->isCopyable) ?: null,
            'copyMessage' => $this->evaluate($this->copyMessage),
            'copyMessageDuration' => $this->evaluate($this->copyMessageDuration),
            'wrap' => $this->evaluate($this->isWrapped) ?: null,
        ];
    }
}
