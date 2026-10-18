<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation;

/**
 * Fluent builder for fully custom navigation (`$panel->navigation(fn (NavigationBuilder $b) => …)`).
 */
class NavigationBuilder
{
    /** @var array<NavigationGroup> */
    protected array $groups = [];

    /** @var array<NavigationItem> */
    protected array $items = [];

    /**
     * @param  array<NavigationItem>  $items
     */
    public function items(array $items): static
    {
        $this->items = [...$this->items, ...$items];

        return $this;
    }

    public function item(NavigationItem $item): static
    {
        $this->items[] = $item;

        return $this;
    }

    /**
     * @param  array<NavigationGroup>  $groups
     */
    public function groups(array $groups): static
    {
        $this->groups = [...$this->groups, ...$groups];

        return $this;
    }

    public function group(NavigationGroup $group): static
    {
        $this->groups[] = $group;

        return $this;
    }

    /**
     * @return array<NavigationGroup>
     */
    public function getNavigation(): array
    {
        $groups = $this->groups;

        if ($this->items !== []) {
            array_unshift($groups, NavigationGroup::make()->items($this->items));
        }

        return $groups;
    }
}
