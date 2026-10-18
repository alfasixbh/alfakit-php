<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Tabs;

use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Concerns\CanModifyQuery;
use AlfaSix\Alfakit\Schemas\DeferredLoading;
use Closure;

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

        return $parent instanceof \AlfaSix\Alfakit\Schemas\Components\Tabs && $parent->isLoadingDeferred();
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }

    protected function getSchemaProps(): array
    {
        return [
            'icon' => $this->getIcon(),
            'iconPosition' => $this->getIcon() ? $this->getIconPosition()->value : null,
            ...$this->isBadgeDeferred() && DeferredLoading::rendersPlaceholders()
                ? ['badgeDeferred' => true]
                : $this->getBadgeSchema(),
        ];
    }
}
