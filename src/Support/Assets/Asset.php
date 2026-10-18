<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Assets;

use InvalidArgumentException;

/**
 * A stylesheet or script a plugin adds to the panel's `<head>`. The path is either a URL
 * (`https://…`, `//…`, `asset('…')`) used as is, or a local file that `php artisan
 * alfakit:assets` publishes to `public/vendor/alfakit/{package}/`.
 */
abstract class Asset
{
    protected ?string $package = null;

    protected bool $isLoadedOnRequest = false;

    final public function __construct(protected string $id, protected string $path = '')
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $id) !== 1) {
            throw new InvalidArgumentException("Alfakit asset ids may only contain letters, numbers, dots, dashes and underscores; [{$id}] given.");
        }
    }

    /**
     * `$path` may be omitted for inline scripts (`Js::make('id')->html(…)`).
     */
    public static function make(string $id, string $path = ''): static
    {
        return new static($id, $path);
    }

    abstract public function getExtension(): string;

    public function getId(): string
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Keep the asset out of the panel's `<head>`; a component loads it when it renders,
     * from `Assets::getStyleHref()` / `Assets::getScriptSrc()` (sent in its schema props)
     * with `loadStyle()` / `loadScript()` from `@alfakit/core`.
     */
    public function loadedOnRequest(bool $condition = true): static
    {
        $this->isLoadedOnRequest = $condition;

        return $this;
    }

    public function isLoadedOnRequest(): bool
    {
        return $this->isLoadedOnRequest;
    }

    /** @internal Set by `Assets::register()`. */
    public function package(?string $package): static
    {
        $this->package = $package;

        return $this;
    }

    public function getPackage(): string
    {
        return $this->package ?? 'app';
    }

    /**
     * URLs (`https://…`, `//…`) are linked as they are; use `asset('…')` for a file that
     * is already in `public/`. Anything else is a local file to publish.
     */
    public function isRemote(): bool
    {
        return str_starts_with($this->path, '//') || preg_match('#^https?://#i', $this->path) === 1;
    }

    /**
     * Where `alfakit:assets` copies a local file, relative to `public/`.
     */
    public function getPublicPath(): string
    {
        return 'vendor/alfakit/'.$this->getPackage().'/'.$this->id.'.'.$this->getExtension();
    }

    /**
     * The URL in the page: remote URLs as given, published files with a version
     * derived from their contents' modification time to bust caches after upgrades.
     */
    public function getUrl(): string
    {
        if ($this->isRemote()) {
            return $this->path;
        }

        $published = public_path($this->getPublicPath());
        $version = is_file($published) ? substr(md5((string) filemtime($published).':'.(string) filesize($published)), 0, 8) : null;

        return asset($this->getPublicPath()).($version !== null ? "?v={$version}" : '');
    }

    /**
     * @return array<string, string|bool>
     */
    abstract public function getHtmlAttributes(): array;
}
