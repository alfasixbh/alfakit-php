<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use AlfaSix\Alfakit\Support\Concerns\HasImageStackStyles;
use AlfaSix\Alfakit\Support\Concerns\HasTemporaryUrlExpiry;
use AlfaSix\Alfakit\Tables\Columns\ImageColumn;
use BackedEnum;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImageEntry extends Entry
{
    use HasImageStackStyles;
    use HasTemporaryUrlExpiry;

    protected string|Closure|null $disk = null;

    protected string|Closure|null $visibility = null;

    protected bool|Closure $isCircular = false;

    protected bool|Closure $isSquare = false;

    protected int|string|Closure|null $size = null;

    protected int|string|Closure|null $height = null;

    protected int|string|Closure|null $width = null;

    protected string|Closure|null $defaultImageUrl = null;

    protected bool|Closure $isStacked = false;

    protected int|Closure|null $limit = null;

    protected string|Closure|null $alt = null;

    protected bool|Closure|null $hasLimitedRemainingText = null;

    protected string|BackedEnum|Closure|null $limitedRemainingTextSize = null;

    protected bool|Closure $shouldCheckFileExistence = false;

    public function disk(string|Closure|null $disk): static
    {
        $this->disk = $disk;

        return $this;
    }

    public function visibility(string|Closure|null $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function circular(bool|Closure $condition = true): static
    {
        $this->isCircular = $condition;

        return $this;
    }

    public function square(bool|Closure $condition = true): static
    {
        $this->isSquare = $condition;

        return $this;
    }

    public function size(int|string|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    public function height(int|string|Closure|null $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function width(int|string|Closure|null $width): static
    {
        $this->width = $width;

        return $this;
    }

    /**
     * Filament's name for `width()`.
     */
    public function imageWidth(int|string|Closure|null $width): static
    {
        return $this->width($width);
    }

    /**
     * Filament's name for `height()`.
     */
    public function imageHeight(int|string|Closure|null $height): static
    {
        return $this->height($height);
    }

    /**
     * Filament's name for `size()`.
     */
    public function imageSize(int|string|Closure|null $size): static
    {
        return $this->size($size);
    }

    /**
     * Alternative text of the images. A closure runs once per image, with `$state` set to
     * that image's path (and `$record` to the record), so each image can describe itself.
     */
    public function alt(string|Closure|null $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function defaultImageUrl(string|Closure|null $url): static
    {
        $this->defaultImageUrl = $url;

        return $this;
    }

    public function stacked(bool|Closure $condition = true): static
    {
        $this->isStacked = $condition;

        return $this;
    }

    public function limit(int|Closure|null $limit = 3): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * With `limit()`, the "+N" for the images left out (shown by default in AlfaKit;
     * `limitedRemainingText(false)` hides it). `size`: `xs`, `sm` (default), `md`, `lg`.
     */
    public function limitedRemainingText(bool|Closure $condition = true, string|BackedEnum|Closure|null $size = null): static
    {
        $this->hasLimitedRemainingText = $condition;
        $this->limitedRemainingTextSize = $size;

        return $this;
    }

    /**
     * Skip paths that do not exist on the disk (one `exists()` call per image), so a
     * missing file falls back to `defaultImageUrl()` instead of a broken image.
     */
    public function checkFileExistence(bool|Closure $condition = true): static
    {
        $this->shouldCheckFileExistence = $condition;

        return $this;
    }

    public function getImageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $disk = Storage::disk($this->evaluate($this->disk) ?? config('alfakit.default_filesystem_disk', config('filesystems.default')));

        if ($this->evaluate($this->shouldCheckFileExistence) && ! $disk->exists($path)) {
            return null;
        }

        try {
            return ($this->evaluate($this->visibility) ?? 'public') === 'private'
                ? $disk->temporaryUrl($path, $this->getTemporaryUrlExpiration())
                : $disk->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The image URLs with the path each came from (null for the default image).
     *
     * @return array{0: list<string>, 1: list<string|null>}
     */
    protected function resolveImages(): array
    {
        $state = $this->getState();
        $paths = array_values(array_filter(Arr::wrap($state instanceof \Illuminate\Support\Collection ? $state->all() : $state), 'filled'));
        $urls = $sources = [];

        foreach ($paths as $path) {
            $url = $this->getImageUrl(is_string($path) ? $path : null);

            if ($url !== null) {
                $urls[] = $url;
                $sources[] = is_string($path) ? $path : null;
            }
        }

        if ($urls === [] && ($default = $this->evaluate($this->defaultImageUrl)) !== null) {
            $urls = [$default];
            $sources = [null];
        }

        return [$urls, $sources];
    }

    protected function getFormattedState(): mixed
    {
        return $this->resolveImages()[0];
    }

    /**
     * One alt text shared by every image, or one per image when a closure gives different
     * texts (`alts`, aligned with the state).
     *
     * @return array{alt: string|null, alts: list<string|null>|null}
     */
    protected function getAltSchemaProps(): array
    {
        if ($this->alt === null) {
            return ['alt' => null, 'alts' => null];
        }

        if (! $this->alt instanceof Closure) {
            return ['alt' => $this->alt, 'alts' => null];
        }

        [, $sources] = $this->resolveImages();
        $alts = array_map(function (?string $path): ?string {
            $alt = $this->evaluate($this->alt, ['state' => $path]);

            return $alt === null ? null : (string) $alt;
        }, $sources);

        if ($alts === []) {
            return ['alt' => null, 'alts' => null];
        }

        return count(array_unique(array_map(static fn (?string $alt): string => (string) $alt, $alts))) === 1
            ? ['alt' => $alts[0], 'alts' => null]
            : ['alt' => null, 'alts' => $alts];
    }

    protected function getSchemaProps(): array
    {
        return [
            'circular' => $this->evaluate($this->isCircular) ?: null,
            'square' => $this->evaluate($this->isSquare) ?: null,
            'size' => $this->evaluate($this->size),
            'height' => $this->evaluate($this->height),
            'width' => $this->evaluate($this->width),
            'stacked' => $this->evaluate($this->isStacked) ?: null,
            'limit' => $this->evaluate($this->limit),
            'limitedRemainingText' => $this->hasLimitedRemainingText === null ? null : (bool) $this->evaluate($this->hasLimitedRemainingText),
            'limitedRemainingTextSize' => ImageColumn::textSize($this->evaluate($this->limitedRemainingTextSize)),
            ...$this->getAltSchemaProps(),
            ...$this->getImageStackSchemaProps(),
            'extraImgAttributes' => $this->getExtraImgAttributes() ?: null,
        ];
    }
}
