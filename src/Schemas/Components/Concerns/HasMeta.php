<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

trait HasMeta
{
    /** @var array<string, mixed> */
    protected array $meta = [];

    /**
     * Arbitrary data passed through to the renderer. Useful for custom frontend components.
     *
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta, bool $merge = true): static
    {
        $this->meta = $merge ? array_merge($this->meta, $meta) : $meta;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMeta(): array
    {
        return array_map($this->evaluate(...), $this->meta);
    }
}
