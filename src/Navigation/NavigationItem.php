<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Navigation;

use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasExtraAttributes;
use AlfaSix\Alfakit\Support\EnumState;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Serializable;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class NavigationItem
{
    use Configurable;
    use EvaluatesClosures;
    use HasExtraAttributes;

    protected string|Htmlable|Closure|null $label = null;

    protected string|Closure|null $key = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $icon = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $activeIcon = null;

    protected string|Closure|null $url = null;

    protected bool|Closure $shouldOpenUrlInNewTab = false;

    protected bool|Closure $isActive = false;

    protected ?Closure $isActiveWhen = null;

    protected string|int|Closure|null $badge = null;

    protected string|Closure|null $badgeColor = null;

    protected string|Closure|null $badgeTooltip = null;

    /** Set while badges are deferred: the item has a badge that loads after the page. */
    protected bool $hasDeferredBadge = false;

    protected string|UnitEnum|Closure|null $group = null;

    protected string|Closure|null $parentItem = null;

    protected int|Closure|null $sort = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    /** @var array<NavigationItem>|Closure */
    protected array|Closure $childItems = [];

    final public function __construct(string|Htmlable|Closure|null $label = null)
    {
        $this->label = $label;
    }

    public static function make(string|Htmlable|Closure|null $label = null): static
    {
        $static = new static($label);
        $static->configure();

        return $static;
    }

    /**
     * A string, or `Htmlable` markup (`new HtmlString('Inbox <b>new</b>')`), which is
     * sanitised before it reaches the browser.
     */
    public function label(string|Htmlable|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * The identifier other items name in `parentItem()` (defaults to the label). Page and
     * resource items are keyed by their class, so `parentItem(UserResource::class)` works.
     */
    public function key(string|Closure|null $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getKey(): string
    {
        $key = $this->evaluate($this->key);

        return is_string($key) && $key !== '' ? $key : $this->getLabel();
    }

    public function icon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function activeIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->activeIcon = $icon;

        return $this;
    }

    public function url(string|Closure|null $url, bool|Closure $shouldOpenInNewTab = false): static
    {
        $this->url = $url;
        $this->shouldOpenUrlInNewTab = $shouldOpenInNewTab;

        return $this;
    }

    public function openUrlInNewTab(bool|Closure $condition = true): static
    {
        $this->shouldOpenUrlInNewTab = $condition;

        return $this;
    }

    /**
     * `fn (): bool`, typically `request()->routeIs('alfakit.admin.pages.*')`.
     */
    public function isActiveWhen(Closure $callback): static
    {
        $this->isActiveWhen = $callback;

        return $this;
    }

    public function badge(string|int|Closure|null $badge, string|Closure|null $color = null): static
    {
        $this->badge = $badge;
        $this->badgeColor = $color;

        return $this;
    }

    /**
     * The badge colour on its own: `fn (string|int|null $badge): string => ...`.
     */
    /**
     * @internal Marks the item as having a badge while the panel defers badges (see `NavigationState`).
     */
    public function markBadgeDeferred(bool $condition = true): static
    {
        $this->hasDeferredBadge = $condition;

        return $this;
    }

    public function badgeColor(string|Closure|null $color): static
    {
        $this->badgeColor = $color;

        return $this;
    }

    public function badgeTooltip(string|Closure|null $tooltip): static
    {
        $this->badgeTooltip = $tooltip;

        return $this;
    }

    /**
     * The group label, or an enum case: its `HasLabel` label names the group and its
     * `HasIcon` icon is the group icon (`NavigationGroup::make(NavigationGroup::Shop)`).
     */
    public function group(string|UnitEnum|Closure|null $group): static
    {
        $this->group = $group;

        return $this;
    }

    public function parentItem(string|Closure|null $parent): static
    {
        $this->parentItem = $parent;

        return $this;
    }

    public function sort(int|Closure|null $sort): static
    {
        $this->sort = $sort;

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

    /**
     * @param  array<NavigationItem>|Closure  $items
     */
    public function childItems(array|Closure $items): static
    {
        $this->childItems = $items;

        return $this;
    }

    /**
     * The label as plain text (an `Htmlable` label without its markup).
     */
    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return $label instanceof Htmlable ? Html::toText($label) : (string) $label;
    }

    /**
     * The sanitised markup of an `Htmlable` label, or null for a plain one.
     */
    public function getLabelHtml(): ?string
    {
        $label = $this->evaluate($this->label);

        return $label instanceof Htmlable ? Html::sanitize($label->toHtml()) : null;
    }

    public function getBadge(): string|int|null
    {
        $badge = $this->evaluate($this->badge);

        return is_string($badge) || is_int($badge) ? $badge : (is_scalar($badge) ? (string) $badge : null);
    }

    /**
     * Colour and tooltip closures receive the badge value as `$badge`.
     */
    public function getBadgeColor(string|int|null $badge = null): ?string
    {
        $color = $this->evaluate($this->badgeColor, ['badge' => $badge ?? $this->getBadge()]);

        return $color instanceof BackedEnum ? (string) $color->value : (is_string($color) ? $color : null);
    }

    public function getBadgeTooltip(string|int|null $badge = null): ?string
    {
        $tooltip = $this->evaluate($this->badgeTooltip, ['badge' => $badge ?? $this->getBadge()]);

        return $tooltip instanceof Htmlable ? $tooltip->toHtml() : (is_scalar($tooltip) ? (string) $tooltip : null);
    }

    /**
     * HTML attributes for the item's link (`class` is merged with its own; event handler
     * attributes are dropped).
     *
     * @return array<string, mixed>
     */
    public function getSanitizedExtraAttributes(): array
    {
        return NavigationGroup::sanitizeAttributes($this->getExtraAttributes());
    }

    public function getIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->icon));
    }

    public function getUrl(): ?string
    {
        return \AlfaSix\Alfakit\Support\Url::sanitize($this->evaluate($this->url));
    }

    /**
     * The group label (an enum group gives its label).
     */
    public function getGroup(): ?string
    {
        $group = $this->getGroupSource();

        return $group instanceof UnitEnum ? EnumState::label($group) : $group;
    }

    /**
     * The group as given: a label or an enum case, to build the group from.
     */
    public function getGroupSource(): string|UnitEnum|null
    {
        $group = $this->evaluate($this->group);

        return $group instanceof UnitEnum || is_string($group) ? $group : null;
    }

    public function getParentItem(): ?string
    {
        return $this->evaluate($this->parentItem);
    }

    public function getSort(): int
    {
        return $this->evaluate($this->sort) ?? -1;
    }

    public function isActive(): bool
    {
        if ($this->isActiveWhen !== null) {
            return (bool) $this->evaluate($this->isActiveWhen);
        }

        $url = $this->getUrl();

        return $url !== null && request()->fullUrlIs($url) || request()->url() === $url;
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->isHidden) || ! $this->evaluate($this->isVisible);
    }

    public function isVisible(): bool
    {
        return ! $this->isHidden();
    }

    /**
     * @return array<NavigationItem>
     */
    public function getChildItems(): array
    {
        return array_values(array_filter($this->evaluate($this->childItems) ?? [], static fn (NavigationItem $item): bool => $item->isVisible()));
    }

    /**
     * Nest items under the item their `parentItem()` names, matched by key (a page or
     * resource class) or by label. Children keep the parent's existing children and are
     * sorted by `sort()`. Items whose parent isn't in the list stay at the top level.
     *
     * @param  array<NavigationItem>  $items
     * @return array<NavigationItem>
     */
    public static function nest(array $items): array
    {
        $children = [];
        $topLevel = [];

        foreach ($items as $item) {
            $parent = $item->getParentItem();
            $parentItem = $parent === null ? null : self::findParent($items, $item, $parent);

            if ($parentItem === null) {
                $topLevel[] = $item;

                continue;
            }

            $children[spl_object_id($parentItem)][] = $item;
        }

        foreach ($items as $item) {
            if (! isset($children[spl_object_id($item)])) {
                continue;
            }

            $merged = [...$item->getChildItems(), ...$children[spl_object_id($item)]];
            usort($merged, static fn (NavigationItem $a, NavigationItem $b): int => $a->getSort() <=> $b->getSort());

            $item->childItems($merged);
        }

        return $topLevel;
    }

    /**
     * @param  array<NavigationItem>  $items
     */
    private static function findParent(array $items, NavigationItem $child, string $parent): ?NavigationItem
    {
        // Keys first, so a class name wins over an item that happens to have that label.
        foreach (['getKey', 'getLabel'] as $method) {
            foreach ($items as $candidate) {
                if ($candidate !== $child && $candidate->getParentItem() === null && $parent === $candidate->{$method}()) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        // Serialise children first so an active grandchild marks every ancestor active.
        $children = array_map(static fn (NavigationItem $item): array => $item->toSchema(), $this->getChildItems());
        $isActive = $this->isActive() || collect($children)->contains(static fn (array $child): bool => ($child['active'] ?? false) === true);

        $labelHtml = $this->getLabelHtml();
        // Deferred badges (`Panel::deferNavigationBadges()`) are not evaluated here.
        $deferBadge = NavigationState::isDeferringBadges();
        $badge = $deferBadge ? null : $this->getBadge();
        // Split navigation (an Inertia once prop): the renderer swaps in `activeIcon` itself.
        $splitting = NavigationState::isSplitting();
        $icon = $this->getIcon();
        $activeIcon = $isActive || $splitting ? Icons::resolve($this->evaluate($this->activeIcon)) : null;

        return Serializable::compact([
            'label' => $labelHtml ?? $this->getLabel(),
            // `label` is sanitised HTML: renderers show it as markup.
            'labelHtml' => $labelHtml !== null ?: null,
            'icon' => $isActive && ! $splitting ? ($activeIcon ?? $icon) : $icon,
            'activeIcon' => $splitting && $activeIcon !== $icon ? $activeIcon : null,
            'url' => $this->getUrl(),
            'openInNewTab' => $this->evaluate($this->shouldOpenUrlInNewTab) ?: null,
            'active' => $isActive ?: null,
            'badge' => $badge,
            'badgeColor' => $deferBadge ? null : $this->getBadgeColor($badge),
            'badgeTooltip' => $deferBadge ? null : $this->getBadgeTooltip($badge),
            'deferredBadge' => $deferBadge && ($this->badge !== null || $this->hasDeferredBadge) ?: null,
            'extraAttributes' => $this->getSanitizedExtraAttributes() ?: null,
            'children' => $children ?: null,
        ]);
    }
}
