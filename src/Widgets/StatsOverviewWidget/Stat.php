<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets\StatsOverviewWidget;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Enums\IconPosition;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

class Stat
{
    use Configurable;
    use EvaluatesClosures;

    protected string|Closure|null $description = null;

    protected string|Closure|null $placeholder = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $descriptionIcon = null;

    protected IconPosition|string|Closure|null $descriptionIconPosition = null;

    protected string|Closure|null $descriptionColor = null;

    protected string|Closure|null $color = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    /** @var array<int|float>|Closure|null */
    protected array|Closure|null $chart = null;

    protected string|Closure|null $chartColor = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    /** @var array<string, mixed> */
    protected array $extraAttributes = [];

    final public function __construct(
        protected string|Htmlable|Closure $label,
        protected mixed $value,
    ) {}

    public static function make(string|Htmlable|Closure $label, mixed $value): static
    {
        $static = new static($label, $value);
        $static->configure();

        return $static;
    }

    /**
     * Shown instead of the value when it is null or an empty string. Set a default for every
     * stat with `Stat::configureUsing(fn (Stat $stat) => $stat->placeholder('—'))`.
     */
    public function placeholder(string|Closure|null $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    public function getPlaceholder(): ?string
    {
        return $this->evaluate($this->placeholder);
    }

    public function description(string|Closure|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * `$position`: `IconPosition::Before` (default) or `IconPosition::After` the description.
     */
    public function descriptionIcon(string|BackedEnum|Htmlable|Closure|null $icon, IconPosition|string|Closure|null $position = null): static
    {
        $this->descriptionIcon = $icon;
        $this->descriptionIconPosition = $position;

        return $this;
    }

    public function getDescriptionIconPosition(): IconPosition
    {
        $position = $this->evaluate($this->descriptionIconPosition);

        return $position instanceof IconPosition ? $position : (IconPosition::tryFrom((string) $position) ?? IconPosition::Before);
    }

    public function descriptionColor(string|Closure|null $color): static
    {
        $this->descriptionColor = $color;

        return $this;
    }

    public function color(string|Closure|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function icon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @param  array<int|float>|Closure|null  $data
     */
    public function chart(array|Closure|null $data): static
    {
        $this->chart = $data;

        return $this;
    }

    public function chartColor(string|Closure|null $color): static
    {
        $this->chartColor = $color;

        return $this;
    }

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function extraAttributes(array $attributes): static
    {
        $this->extraAttributes = $attributes;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $label = $this->evaluate($this->label);
        $value = $this->evaluate($this->value);

        return [
            'label' => $label instanceof Htmlable ? $label->toHtml() : (string) $label,
            // Always sent, also when null: the renderers show the placeholder for null / ''.
            'value' => is_scalar($value) || $value === null ? $value : (string) $value,
        ] + Serializable::compact([
            'placeholder' => $value === null || $value === '' ? $this->getPlaceholder() : null,
            'description' => $this->evaluate($this->description),
            'descriptionIcon' => Icons::resolve($this->evaluate($this->descriptionIcon)),
            // Only `after` is sent; the icon sits before the description by default.
            'descriptionIconPosition' => $this->getDescriptionIconPosition() === IconPosition::After ? 'after' : null,
            'descriptionColor' => $this->evaluate($this->descriptionColor),
            'color' => $this->evaluate($this->color),
            'icon' => Icons::resolve($this->evaluate($this->icon)),
            'chart' => $this->evaluate($this->chart),
            'chartColor' => $this->evaluate($this->chartColor) ?? $this->evaluate($this->color),
            'url' => \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url)),
            'openUrlInNewTab' => $this->evaluate($this->shouldOpenUrlInNewTab) ?: null,
            'extraAttributes' => $this->extraAttributes ?: null,
        ]);
    }
}
