<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;

/**
 * An entry in the user menu or tenant menu. Menus also accept `Action`s, which can run
 * server logic and open modals; prefer those in new code.
 */
class MenuItem
{
    use Configurable;
    use EvaluatesClosures;

    protected string|Closure|null $label = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected string|Closure|null $color = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    protected int|Closure|null $sort = null;

    protected bool $isPostRequest = false;

    final public function __construct() {}

    public static function make(): static
    {
        $static = new static;
        $static->configure();

        return $static;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function icon(string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function postToUrl(string|Closure|null $url): static
    {
        $this->url = $url;
        $this->isPostRequest = true;

        return $this;
    }

    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    public function sort(int|Closure|null $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    /**
     * The icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved like
     * every other icon, so renderers receive the icon name to draw.
     */
    public function getIcon(): ?string
    {
        $icon = $this->evaluate($this->icon);

        return Icons::resolve($icon);
    }

    public function getLabel(): ?string
    {
        $label = $this->evaluate($this->label);

        return is_string($label) ? $label : null;
    }

    public function getUrl(): ?string
    {
        return \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url));
    }

    public function getColor(): ?string
    {
        $color = $this->evaluate($this->color);

        return is_string($color) ? $color : null;
    }

    public function shouldOpenUrlInNewTab(): bool
    {
        return (bool) $this->evaluate($this->shouldOpenUrlInNewTab);
    }

    public function shouldPostToUrl(): bool
    {
        return $this->isPostRequest;
    }

    public function isVisible(): bool
    {
        return ! $this->evaluate($this->isHidden) && $this->evaluate($this->isVisible);
    }

    public function getSort(): int
    {
        return $this->evaluate($this->sort) ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        return Serializable::compact([
            'label' => $this->evaluate($this->label),
            'icon' => $this->getIcon(),
            'url' => \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url)),
            'openInNewTab' => $this->evaluate($this->shouldOpenUrlInNewTab) ?: null,
            'color' => $this->evaluate($this->color),
            'method' => $this->isPostRequest ? 'post' : null,
        ]);
    }
}
