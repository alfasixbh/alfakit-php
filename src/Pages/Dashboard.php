<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages;

use AlfaSix\Alfakit\Pages\Dashboard\Concerns\IsCustomizable;
use AlfaSix\Alfakit\Widgets\Widget;
use ReflectionProperty;

/**
 * The default landing page: a grid of the panel's widgets.
 */
class Dashboard extends Page
{
    use IsCustomizable;

    protected static ?string $navigationIcon = 'layout-dashboard';

    protected static ?int $navigationSort = -2;

    protected static ?string $routePath = '/';

    /**
     * The main dashboard lives at the panel root. Further dashboards (any subclass not named
     * `Dashboard` that doesn't declare its own `$routePath`) get `/{slug}`, so a panel can
     * have several, each with its own route and widgets.
     */
    public static function getRoutePath(): string
    {
        if (static::$routePath === '/' && ! static::isMainDashboard()) {
            return '/'.static::getSlug();
        }

        return parent::getRoutePath();
    }

    public static function isMainDashboard(): bool
    {
        if (static::class === self::class || class_basename(static::class) === 'Dashboard') {
            return true;
        }

        return (new ReflectionProperty(static::class, 'routePath'))->getDeclaringClass()->getName() !== self::class;
    }

    public static function getNavigationLabel(): string
    {
        $label = static::getNavigationOverride('label');

        return is_string($label) ? $label : static::$navigationLabel ?? static::$title ?? (static::isMainDashboard() ? __('alfakit::panel.dashboard.title') : \AlfaSix\Alfakit\Support\Html::toText(parent::getNavigationLabel()));
    }

    public function getTitle(): string
    {
        return static::$title ?? \AlfaSix\Alfakit\Support\Html::toText(static::getNavigationLabel());
    }

    /**
     * @return array<class-string<Widget>|Widget>
     */
    public function getWidgets(): array
    {
        return $this->getPanel()->getWidgets();
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * A customizable dashboard shows the user's own widgets (in their order and spans)
     * instead of `getWidgets()`; header and footer widgets stay as they are.
     *
     * @return array<string, Widget>
     */
    public function getAllWidgetInstances(): array
    {
        if (! $this->isCustomizable()) {
            return parent::getAllWidgetInstances();
        }

        return $this->cachedWidgets ??= [
            ...$this->instantiateWidgets($this->getHeaderWidgets()),
            ...$this->instantiateWidgets($this->getFooterWidgets()),
            ...$this->getVisibleLayoutWidgets(),
        ];
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->flushDashboardLayoutCaches();
    }

    protected function getContentBlocks(): array
    {
        if ($this->isCustomizable()) {
            return [$this->serializeCustomizableWidgets()];
        }

        $widgets = $this->serializeWidgets($this->getWidgets(), $this->getColumns(), 'widgets');

        return $widgets === null ? [] : [$widgets];
    }
}
