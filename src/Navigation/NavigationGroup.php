<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;
use UnitEnum;

class NavigationGroup
{
    use Configurable;
    use EvaluatesClosures;

    protected string|Closure|null $label = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected bool|Closure $isCollapsible = true;

    protected bool|Closure $isCollapsed = false;

    /** @var array<NavigationItem> */
    protected array $items = [];

    /** @var array<string, mixed>|Closure */
    protected array|Closure $extraSidebarAttributes = [];

    /** @var array<string, mixed>|Closure */
    protected array|Closure $extraTopbarAttributes = [];

    /**
     * The enum case this group was made from, if any.
     */
    protected ?UnitEnum $enum = null;

    /**
     * A label, or an enum case implementing `HasLabel` (the label) and optionally
     * `HasIcon` (the group icon): `NavigationGroup::make(NavigationGroup::Shop)`.
     */
    final public function __construct(string|UnitEnum|Closure|null $label = null)
    {
        if ($label instanceof UnitEnum) {
            $this->enum = $label;
            $this->icon = EnumState::icon($label);
            $label = EnumState::label($label);
        }

        $this->label = $label;
    }

    public static function make(string|UnitEnum|Closure|null $label = null): static
    {
        $static = new static($label);
        $static->configure();

        return $static;
    }

    public function getEnum(): ?UnitEnum
    {
        return $this->enum;
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

    public function collapsible(bool|Closure $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    public function collapsed(bool|Closure $condition = true): static
    {
        $this->isCollapsed = $condition;

        return $this;
    }

    /**
     * HTML attributes for the group in the sidebar (`class` is merged with its own).
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraSidebarAttributes(array|Closure $attributes): static
    {
        $this->extraSidebarAttributes = $attributes;

        return $this;
    }

    /**
     * HTML attributes for the group in the top navigation (`class` is merged with its own).
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraTopbarAttributes(array|Closure $attributes): static
    {
        $this->extraTopbarAttributes = $attributes;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraSidebarAttributes(): array
    {
        return self::sanitizeAttributes($this->evaluate($this->extraSidebarAttributes));
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraTopbarAttributes(): array
    {
        return self::sanitizeAttributes($this->evaluate($this->extraTopbarAttributes));
    }

    /**
     * Plain attribute values with valid names; event handlers (`on*`) are dropped.
     *
     * @return array<string, mixed>
     */
    public static function sanitizeAttributes(mixed $attributes): array
    {
        $clean = [];

        foreach (is_array($attributes) ? $attributes : [] as $name => $value) {
            if (is_string($name) && preg_match('/^[a-zA-Z_:][-a-zA-Z0-9_:.]*$/', $name) === 1 && ! str_starts_with(strtolower($name), 'on') && (is_scalar($value) || $value === null)) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<NavigationItem>  $items
     */
    public function items(array $items): static
    {
        $this->items = $items;

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
        return $this->evaluate($this->label);
    }

    /**
     * @return array<NavigationItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $items = array_values(array_filter($this->items, static fn (NavigationItem $item): bool => $item->isVisible()));

        usort($items, static fn (NavigationItem $a, NavigationItem $b): int => $a->getSort() <=> $b->getSort());

        return Serializable::compact([
            'label' => $this->getLabel(),
            'icon' => $this->getIcon(),
            'collapsible' => (bool) $this->evaluate($this->isCollapsible),
            'collapsed' => $this->evaluate($this->isCollapsed) ?: null,
            'items' => array_map(static fn (NavigationItem $item): array => $item->toSchema(), $items),
            'extraSidebarAttributes' => $this->getExtraSidebarAttributes() ?: null,
            'extraTopbarAttributes' => $this->getExtraTopbarAttributes() ?: null,
        ]);
    }
}
