<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Concerns;

use Closure;

/**
 * `ring()`, `overlap()` and `extraImgAttributes()` of `ImageColumn` and `ImageEntry`.
 */
trait HasImageStackStyles
{
    protected int|Closure|null $ring = null;

    protected int|Closure|null $overlap = null;

    /** @var array<array<string, mixed>|Closure> */
    protected array $extraImgAttributes = [];

    /**
     * Width in pixels (0–8) of the ring separating stacked images (default 2).
     */
    public function ring(int|Closure|null $ring): static
    {
        $this->ring = $ring;

        return $this;
    }

    public function getRing(): ?int
    {
        $ring = $this->evaluate($this->ring);

        return $ring === null ? null : max(0, min(8, (int) $ring));
    }

    /**
     * How far stacked images overlap, in quarter rems (0–8, default 2).
     */
    public function overlap(int|Closure|null $overlap): static
    {
        $this->overlap = $overlap;

        return $this;
    }

    public function getOverlap(): ?int
    {
        $overlap = $this->evaluate($this->overlap);

        return $overlap === null ? null : max(0, min(8, (int) $overlap));
    }

    /**
     * HTML attributes for every `<img>` (`loading`, `class`, `data-*`, …); `class` is
     * merged with the component's classes.
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraImgAttributes(array|Closure $attributes, bool $merge = false): static
    {
        $this->extraImgAttributes = $merge ? [...$this->extraImgAttributes, $attributes] : [$attributes];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraImgAttributes(): array
    {
        $attributes = [];

        foreach ($this->extraImgAttributes as $item) {
            $attributes = [...$attributes, ...($this->evaluate($item) ?? [])];
        }

        return $attributes;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getImageStackSchemaProps(): array
    {
        return [
            'ring' => $this->getRing(),
            'overlap' => $this->getOverlap(),
        ];
    }
}
