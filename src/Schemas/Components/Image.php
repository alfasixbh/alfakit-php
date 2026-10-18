<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Support\Enums\Alignment;
use Closure;

/**
 * An image from a URL (prime component).
 *
 * ```php
 * Image::make(asset('images/chart.png'), 'Sales chart')->imageHeight('12rem')->alignCenter()
 * ```
 */
class Image extends Component
{
    protected string|Closure $url;

    protected string|Closure $alt;

    protected string|int|Closure|null $imageWidth = null;

    protected string|int|Closure|null $imageHeight = null;

    protected Alignment|string|Closure|null $alignment = null;

    protected bool|Closure $isCircular = false;

    protected string|Closure|null $tooltip = null;

    final public function __construct(string|Closure $url, string|Closure $alt = '')
    {
        $this->url = $url;
        $this->alt = $alt;
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
    }

    /**
     * A CSS length (`8rem`, `120px`); integers are pixels.
     */
    public function imageWidth(string|int|Closure|null $width): static
    {
        $this->imageWidth = $width;

        return $this;
    }

    /**
     * A CSS length (`8rem`, `120px`); integers are pixels.
     */
    public function imageHeight(string|int|Closure|null $height): static
    {
        $this->imageHeight = $height;

        return $this;
    }

    public function imageSize(string|int|Closure|null $size): static
    {
        return $this->imageWidth($size)->imageHeight($size);
    }

    public function alignment(Alignment|string|Closure|null $alignment): static
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

    public function circular(bool|Closure $condition = true): static
    {
        $this->isCircular = $condition;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    protected function getSchemaProps(): array
    {
        $alignment = $this->evaluate($this->alignment);

        return [
            'url' => (string) $this->evaluate($this->url),
            'alt' => (string) $this->evaluate($this->alt),
            'imageWidth' => self::cssLength($this->evaluate($this->imageWidth)),
            'imageHeight' => self::cssLength($this->evaluate($this->imageHeight)),
            'alignment' => $alignment instanceof Alignment ? $alignment->value : $alignment,
            'circular' => $this->evaluate($this->isCircular) ?: null,
            'tooltip' => $this->evaluate($this->tooltip),
        ];
    }

    protected static function cssLength(string|int|null $value): ?string
    {
        return is_int($value) ? "{$value}px" : $value;
    }
}
