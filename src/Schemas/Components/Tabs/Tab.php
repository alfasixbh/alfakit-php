<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Tabs;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Tabs;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Concerns\CanModifyQuery;
use AlfaSix\Alfakit\Schemas\DeferredLoading;
use Closure;
use Illuminate\Support\Str;

class Tab extends Group
{
    use CanModifyQuery;
    use HasIcon;

    protected string|int|Closure|null $badge = null;

    protected string|Closure|null $badgeColor = null;

    protected string|Closure|null $badgeTooltip = null;

    public function __construct(string|Closure|null $label = null)
    {
        $this->label($label);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        $this->columns(['default' => 1, 'lg' => 2]);
    }

    public function badge(string|int|Closure|null $badge): static
    {
        $this->badge = $badge;

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
     * `badge`, `badgeColor` and `badgeTooltip`, as the deferred-badge request returns them.
     *
     * @return array<string, string|int|null>
     */
    public function getBadgeSchema(): array
    {
        return [
            'badge' => $this->getBadge(),
            'badgeColor' => $this->getBadgeColor(),
            'badgeTooltip' => $this->getBadgeTooltip(),
        ];
    }

    public function getBadgeTooltip(): ?string
    {
        $tooltip = $this->evaluate($this->badgeTooltip, ['badge' => $this->getBadge()]);

        return is_string($tooltip) && $tooltip !== '' ? $tooltip : null;
    }

    /**
     * Deferred by its own `deferLoading()` or by the tabs's.
     */
    public function isLoadingDeferred(): bool
    {
        if (parent::isLoadingDeferred()) {
            return true;
        }

        $parent = $this->hasContainer() ? $this->getContainer()->getParentComponent() : null;

        return $parent instanceof Tabs && $parent->isLoadingDeferred();
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }

    /**
     * The tab's value in the query string (`Tabs::persistTabInQueryString()`): its `id()`,
     * its explicit `key()`, else the slug of its label ("Billing address" →
     * `billing-address`). A slug an earlier sibling already uses gets `-2`, `-3`…
     */
    public function getQueryStringValue(): string
    {
        if (! $this->hasContainer()) {
            return $this->getOwnQueryStringValue();
        }

        $taken = [];

        foreach ($this->getContainer()->getComponents(withHidden: true) as $sibling) {
            if (! $sibling instanceof self) {
                continue;
            }

            $value = $sibling->getOwnQueryStringValue();
            $unique = $value;

            for ($suffix = 2; in_array($unique, $taken, true); $suffix++) {
                $unique = "{$value}-{$suffix}";
            }

            if ($sibling === $this) {
                return $unique;
            }

            $taken[] = $unique;
        }

        return $this->getOwnQueryStringValue();
    }

    protected function getOwnQueryStringValue(): string
    {
        $explicit = $this->getId() ?? $this->evaluate($this->key);

        if (filled($explicit)) {
            return (string) $explicit;
        }

        $slug = Str::slug(\AlfaSix\Alfakit\Support\Html::toText($this->getLabel()));

        return $slug !== '' ? $slug : Str::afterLast($this->getKey(), '.');
    }

    protected function isInQueryStringTabs(): bool
    {
        $parent = $this->hasContainer() ? $this->getContainer()->getParentComponent() : null;

        return $parent instanceof Tabs && $parent->isTabPersistedInQueryString();
    }

    protected function getSchemaProps(): array
    {
        return [
            'queryValue' => $this->isInQueryStringTabs() ? $this->getQueryStringValue() : null,
            'icon' => $this->getIcon(),
            'iconPosition' => $this->getIcon() ? $this->getIconPosition()->value : null,
            ...$this->isBadgeDeferred() && DeferredLoading::rendersPlaceholders()
                ? ['badgeDeferred' => true]
                : $this->getBadgeSchema(),
        ];
    }
}
