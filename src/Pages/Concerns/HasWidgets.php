<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Concerns;

use AlfaSix\Alfakit\Widgets\Widget;
use ReflectionProperty;

trait HasWidgets
{
    /** @var array<string, Widget>|null */
    protected ?array $cachedWidgets = null;

    /**
     * @return array<class-string<Widget>|Widget>
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }

    /**
     * @return array<class-string<Widget>|Widget>
     */
    protected function getFooterWidgets(): array
    {
        return [];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getFooterWidgetsColumns(): int|array
    {
        return 2;
    }

    /**
     * Data for the page's widgets: each widget receives the entries it declares as public
     * properties (others are ignored). Properties passed to `Widget::make([...])` win.
     *
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [];
    }

    /**
     * Set the page's widget data on the public properties the widget declares.
     */
    protected function fillWidgetData(Widget $widget): void
    {
        $given = $widget->getMakeProperties();

        foreach ($this->getWidgetData() as $name => $value) {
            if (! is_string($name) || array_key_exists($name, $given) || ! property_exists($widget, $name)) {
                continue;
            }

            $property = new ReflectionProperty($widget, $name);

            if ($property->isPublic() && ! $property->isStatic() && ! $property->isReadOnly()) {
                $widget->{$name} = $value;
            }
        }
    }

    /**
     * @param  array<class-string<Widget>|Widget>  $widgets
     * @return array<Widget>
     */
    protected function instantiateWidgets(array $widgets): array
    {
        $instances = [];

        foreach ($widgets as $widget) {
            $instance = $widget instanceof Widget ? $widget : $widget::make();
            $instance->page($this);
            $this->fillWidgetData($instance);

            if (method_exists($this, 'getRecord') && ($record = $this->getRecord()) !== null) {
                $instance->record($record);
            }

            if (! $instance::canView()) {
                continue;
            }

            $instances[$instance->getId()] = $instance;
        }

        return $instances;
    }

    /**
     * @return array<string, Widget>
     */
    public function getAllWidgetInstances(): array
    {
        return $this->cachedWidgets ??= [
            ...$this->instantiateWidgets($this->getHeaderWidgets()),
            ...$this->instantiateWidgets($this->getFooterWidgets()),
            ...(method_exists($this, 'getWidgets') ? $this->instantiateWidgets($this->getWidgets()) : []),
        ];
    }

    public function getWidgetInstance(string $id): ?Widget
    {
        return $this->getAllWidgetInstances()[$id] ?? ($this->getAllWidgetInstances()[Widget::idFromClass($id)] ?? null);
    }

    /**
     * @param  array<class-string<Widget>|Widget>  $widgets
     * @param  int|array<string, int|null>  $columns
     * @return array<string, mixed>|null
     */
    protected function serializeWidgets(array $widgets, int|array $columns, string $key): ?array
    {
        $instances = array_values(array_filter(
            $this->getAllWidgetInstances(),
            static fn (Widget $instance) => in_array($instance::class, array_map(static fn ($w) => $w instanceof Widget ? $w::class : $w, $widgets), true)
                || in_array($instance, $widgets, true),
        ));

        if ($instances === []) {
            return null;
        }

        usort($instances, static fn (Widget $a, Widget $b): int => $a->getSort() <=> $b->getSort());

        return [
            'type' => 'Widgets',
            'key' => $key,
            'columns' => is_array($columns) ? $columns : ['default' => 1, 'lg' => $columns],
            'widgets' => array_map(
                fn (Widget $widget): array => $this->rendersLazyPlaceholders() && $widget::isLazy() ? $widget->toLazySchema() : $widget->toSchema(),
                $instances,
            ),
        ];
    }
}
