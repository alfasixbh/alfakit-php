<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Assets;

/**
 * `Css::make('blog-styles', __DIR__.'/../resources/dist/blog.css')`
 */
class Css extends Asset
{
    protected ?string $media = null;

    public function media(?string $media): static
    {
        $this->media = $media;

        return $this;
    }

    public function getExtension(): string
    {
        return 'css';
    }

    public function getHtmlAttributes(): array
    {
        return array_filter([
            'rel' => 'stylesheet',
            'href' => $this->getUrl(),
            'media' => $this->media,
            'data-alfakit-asset' => $this->getPackage().'/'.$this->getId(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
