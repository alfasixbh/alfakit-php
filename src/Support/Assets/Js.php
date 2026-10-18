<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Assets;

use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * `Js::make('blog-widgets', __DIR__.'/../resources/dist/blog.js')->module()`
 *
 * Scripts load deferred by default, so they never block the first paint. `html()` renders
 * inline markup instead of a file: `Js::make('analytics')->html('<script>…</script>')`.
 */
class Js extends Asset
{
    protected string|Htmlable|Closure|null $html = null;

    protected bool $isModule = false;

    protected bool $isDeferred = true;

    protected bool $isAsync = false;

    public function module(bool $condition = true): static
    {
        $this->isModule = $condition;

        return $this;
    }

    public function defer(bool $condition = true): static
    {
        $this->isDeferred = $condition;

        return $this;
    }

    public function async(bool $condition = true): static
    {
        $this->isAsync = $condition;

        return $this;
    }

    /**
     * Render this markup in the panel's `<head>` instead of loading a file (Filament's
     * `Js::make()->html()`). `<script>` tags without a `nonce` get the CSP nonce
     * (`Vite::cspNonce()`), so inline scripts pass a nonce-based Content Security Policy.
     * A string without a `<script>` tag is wrapped in one. The markup is trusted: never
     * build it from user input.
     */
    public function html(string|Htmlable|Closure|null $html): static
    {
        $this->html = $html;

        return $this;
    }

    public function isInline(): bool
    {
        return $this->html !== null;
    }

    /**
     * The inline markup, with the nonce added to its `<script>` tags.
     */
    public function getInlineHtml(?string $nonce = null): ?string
    {
        $html = $this->html instanceof Closure ? ($this->html)() : $this->html;

        if ($html === null) {
            return null;
        }

        $html = $html instanceof Htmlable ? $html->toHtml() : (string) $html;

        if (preg_match('/<script\b/i', $html) !== 1) {
            $html = '<script data-alfakit-asset="'.e($this->getPackage().'/'.$this->getId()).'">'.$html.'</script>';
        }

        if ($nonce === null || $nonce === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<script\b(?![^>]*\bnonce=)([^>]*)>/i',
            static fn (array $match): string => '<script nonce="'.e($nonce).'"'.$match[1].'>',
            $html,
        );
    }

    /**
     * Inline scripts have nothing to publish (`alfakit:assets` skips them).
     */
    public function isRemote(): bool
    {
        return $this->isInline() || parent::isRemote();
    }

    public function getExtension(): string
    {
        return 'js';
    }

    public function getHtmlAttributes(): array
    {
        return array_filter([
            'src' => $this->getUrl(),
            'type' => $this->isModule ? 'module' : null,
            // Module scripts are deferred already.
            'defer' => $this->isDeferred && ! $this->isModule && ! $this->isAsync ? true : null,
            'async' => $this->isAsync ? true : null,
            'data-alfakit-asset' => $this->getPackage().'/'.$this->getId(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
