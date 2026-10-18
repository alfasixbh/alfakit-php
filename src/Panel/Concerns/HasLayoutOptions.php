<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Panel\Concerns;

use AlfaSix\Alfakit\Navigation\NavigationGroup;
use AlfaSix\Alfakit\Support\Enums\Width;
use Closure;

/**
 * Sidebar sizing, navigation group collapsing and the width of simple (auth) pages.
 */
trait HasLayoutOptions
{
    protected bool|Closure $hasCollapsibleNavigationGroups = true;

    protected string|Closure|null $sidebarWidth = null;

    protected string|Closure|null $collapsedSidebarWidth = null;

    protected Width|string|Closure|null $simplePageMaxContentWidth = null;

    /**
     * Whether users can collapse navigation groups. `false` keeps every group open and
     * hides the toggles, whatever each group says.
     */
    public function collapsibleNavigationGroups(bool|Closure $condition = true): static
    {
        $this->hasCollapsibleNavigationGroups = $condition;

        return $this;
    }

    public function hasCollapsibleNavigationGroups(): bool
    {
        return (bool) $this->evaluate($this->hasCollapsibleNavigationGroups);
    }

    /**
     * Width of the open sidebar as a CSS length (`18rem`, `280px`).
     */
    public function sidebarWidth(string|Closure|null $width): static
    {
        $this->sidebarWidth = $width;

        return $this;
    }

    public function getSidebarWidth(): ?string
    {
        return self::sanitizeCssLength($this->evaluate($this->sidebarWidth));
    }

    /**
     * Width of the sidebar collapsed to icons, as a CSS length (`4rem`).
     */
    public function collapsedSidebarWidth(string|Closure|null $width): static
    {
        $this->collapsedSidebarWidth = $width;

        return $this;
    }

    public function getCollapsedSidebarWidth(): ?string
    {
        return self::sanitizeCssLength($this->evaluate($this->collapsedSidebarWidth));
    }

    /**
     * Maximum width of simple pages: login, registration, password reset and other pages
     * with the `auth` layout. Defaults to `md`.
     */
    public function simplePageMaxContentWidth(Width|string|Closure|null $width): static
    {
        $this->simplePageMaxContentWidth = $width;

        return $this;
    }

    public function getSimplePageMaxContentWidth(): ?string
    {
        $width = $this->evaluate($this->simplePageMaxContentWidth);

        return $width instanceof Width ? $width->value : $width;
    }

    /**
     * Clusters are pages, so they are discovered like pages.
     */
    public function discoverClusters(string $in, string $for): static
    {
        return $this->discoverPages($in, $for);
    }

    /**
     * Apply panel-wide navigation options to the built groups.
     *
     * @param  array<NavigationGroup>  $groups
     * @return array<NavigationGroup>
     */
    public function applyNavigationGroupOptions(array $groups): array
    {
        if (! $this->hasCollapsibleNavigationGroups()) {
            foreach ($groups as $group) {
                $group->collapsible(false)->collapsed(false);
            }
        }

        return $groups;
    }

    /**
     * A CSS length the renderers may put in a custom property, or null when the value is
     * anything else (so a panel option can never inject other declarations).
     */
    protected static function sanitizeCssLength(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d+(\.\d+)?(px|rem|em|ch|vw|%)$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getLayoutOptionsSchema(): array
    {
        return [
            'collapsibleNavigationGroups' => $this->hasCollapsibleNavigationGroups() ? null : false,
            'sidebarWidth' => $this->getSidebarWidth(),
            'collapsedSidebarWidth' => $this->getCollapsedSidebarWidth(),
            'simplePageMaxContentWidth' => $this->getSimplePageMaxContentWidth(),
        ];
    }
}
