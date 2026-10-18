<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use AlfaSix\Alfakit\Support\Concerns\HasImageStackStyles;
use AlfaSix\Alfakit\Support\Concerns\HasTemporaryUrlExpiry;
use BackedEnum;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImageColumn extends Column
{
    use HasImageStackStyles;
    use HasTemporaryUrlExpiry;

    protected string|Closure|null $disk = null;

    protected string|Closure|null $visibility = null;

    protected bool|Closure $isCircular = false;

    protected bool|Closure $isSquare = false;

    protected int|string|Closure|null $size = 40;

    protected int|string|Closure|null $height = null;

    protected string|Closure|null $width = null;

    protected string|Closure|null $defaultImageUrl = null;

    protected bool|Closure $isStacked = false;

    protected int|Closure|null $limit = null;

    protected bool|Closure $hasLimitedRemainingText = false;

    protected bool|Closure $shouldCheckFileExistence = false;

    protected string|BackedEnum|Closure|null $limitedRemainingTextSize = null;

    protected bool|Closure $isWrapped = false;

    protected string|Closure|null $alt = null;

    /**
     * Alternative text for the images: a string or `fn ($record, $state): ?string`.
     */
    public function alt(string|Closure|null $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function getAlt(): ?string
    {
        $alt = $this->evaluate($this->alt);

        return $alt === null ? null : (string) $alt;
    }

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

    public function width(string|Closure|null $width): static
    {
        $this->width = $width;

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
     * With `limit()`, show "+N" for the images left out. `size`: `xs`, `sm` (default),
     * `md`, `lg` or a `TextSize` / `Size` case.
     */
    public function limitedRemainingText(bool|Closure $condition = true, string|BackedEnum|Closure|null $size = null): static
    {
        $this->hasLimitedRemainingText = $condition;
        $this->limitedRemainingTextSize = $size;

        return $this;
    }

    /**
     * Let several images wrap onto more lines instead of one row.
     */
    public function wrap(bool|Closure $condition = true): static
    {
        $this->isWrapped = $condition;

        return $this;
    }

    public function checkFileExistence(bool|Closure $condition = true): static
    {
        $this->shouldCheckFileExistence = $condition;

        return $this;
    }

    public function getDiskName(): string
    {
        return $this->evaluate($this->disk) ?? config('alfakit.default_filesystem_disk', config('filesystems.default'));
    }

    public function getImageUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        $disk = Storage::disk($this->getDiskName());

        if ($this->evaluate($this->shouldCheckFileExistence) && ! $disk->exists($path)) {
            return null;
        }

        try {
            if (($this->evaluate($this->visibility) ?? 'public') === 'private') {
                return $disk->temporaryUrl($path, $this->getTemporaryUrlExpiration());
            }

            return $disk->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    protected function getFormattedState(): mixed
    {
        $state = $this->getState();
        $paths = array_values(array_filter(Arr::wrap($state instanceof \Illuminate\Support\Collection ? $state->all() : $state), 'filled'));
        $urls = array_values(array_filter(array_map(fn (mixed $path): ?string => $this->getImageUrl(is_string($path) ? $path : null), $paths)));

        if ($urls === [] && ($default = $this->evaluate($this->defaultImageUrl)) !== null) {
            $urls = [$default];
        }

        return $urls;
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
            'limitedRemainingText' => $this->evaluate($this->hasLimitedRemainingText) ?: null,
            'limitedRemainingTextSize' => self::textSize($this->evaluate($this->limitedRemainingTextSize)),
            'wrap' => $this->evaluate($this->isWrapped) ?: null,
            ...$this->getImageStackSchemaProps(),
        ];
    }

    /**
     * `TextSize::Large`, `Size::Small`, `'lg'`, `'large'` → `lg`.
     */
    public static function textSize(mixed $size): ?string
    {
        if ($size instanceof BackedEnum) {
            $size = $size->value;
        }

        if (! is_string($size)) {
            return null;
        }

        return match (strtolower($size)) {
            'xs', 'extrasmall', 'extra-small', 'extra_small' => 'xs',
            'sm', 'small' => 'sm',
            'md', 'base', 'medium' => 'md',
            'lg', 'large', 'xl' => 'lg',
            default => null,
        };
    }

    protected function getCellProps(): array
    {
        return ['alt' => $this->getAlt(), 'extraImgAttributes' => $this->getExtraImgAttributes() ?: null];
    }
}
