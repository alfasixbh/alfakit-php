<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Pages\Dashboard\Concerns;

use AlfaSix\Alfakit\Pages\Dashboard\Layouts\DashboardLayout;
use AlfaSix\Alfakit\Widgets\Widget;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Personal dashboards (`Dashboard::customizable()` or the panel's `customizableDashboards()`):
 * each user reorders, resizes, hides and adds widgets in an "Edit dashboard" mode. Their
 * layout is stored per panel, dashboard, user and tenant (`alfakit_dashboard_layouts`); the
 * developer's `getWidgets()` / `getColumns()` stay the default ("Reset to default").
 *
 * Widgets offered in the library: `getAvailableWidgets()` (the default widgets plus the
 * panel's `availableDashboardWidgets()`), each still filtered by its `canView()`.
 */
trait IsCustomizable
{
    /**
     * `Dashboard::customizable()` per dashboard class (null: follow the panel).
     *
     * @var array<class-string, bool>
     */
    protected static array $customizableDashboards = [];

    /** @var array<string, Widget>|null */
    protected ?array $cachedLibraryWidgets = null;

    /** @var array<int, array{id: string, span: int|null, hidden: bool}>|null */
    protected ?array $cachedDashboardLayout = null;

    protected ?bool $hasStoredDashboardLayout = null;

    /**
     * Let users personalise this dashboard (call it in a service provider); `false` turns
     * it off even when the panel has `customizableDashboards()`, null follows the panel.
     */
    public static function customizable(?bool $condition = true): void
    {
        if ($condition === null) {
            unset(static::$customizableDashboards[static::class]);

            return;
        }

        static::$customizableDashboards[static::class] = $condition;
    }

    public function isCustomizable(): bool
    {
        if ($this->getDashboardLayoutUser() === null) {
            return false;
        }

        return static::$customizableDashboards[static::class] ?? $this->getPanel()->hasCustomizableDashboards();
    }

    /**
     * Widgets the user may add from the library: the default widgets plus the panel's
     * `availableDashboardWidgets()`. Override to offer a different set per dashboard.
     *
     * @return array<class-string<Widget>|Widget>
     */
    public function getAvailableWidgets(): array
    {
        return [...$this->getWidgets(), ...$this->getPanel()->getAvailableDashboardWidgets()];
    }

    /**
     * The library: every available widget the user may see, by id.
     *
     * @return array<string, Widget>
     */
    public function getLibraryWidgets(): array
    {
        return $this->cachedLibraryWidgets ??= $this->instantiateWidgets($this->getAvailableWidgets());
    }

    /**
     * The developer's layout: `getWidgets()` in their `$sort` order, all visible.
     *
     * @return array<int, array{id: string, span: int|null, hidden: bool}>
     */
    public function getDefaultDashboardLayout(): array
    {
        $defaults = array_values(array_filter(
            $this->getLibraryWidgets(),
            fn (Widget $widget): bool => $this->isDefaultDashboardWidget($widget),
        ));

        usort($defaults, static fn (Widget $a, Widget $b): int => $a->getSort() <=> $b->getSort());

        return array_map(static fn (Widget $widget): array => ['id' => $widget->getId(), 'span' => null, 'hidden' => false], $defaults);
    }

    /**
     * The user's layout (or the default): entries for widgets that are no longer available
     * are dropped, default widgets the layout doesn't mention are appended.
     *
     * @return array<int, array{id: string, span: int|null, hidden: bool}>
     */
    public function getDashboardLayout(): array
    {
        if ($this->cachedDashboardLayout !== null) {
            return $this->cachedDashboardLayout;
        }

        $user = $this->getDashboardLayoutUser();
        $stored = $user === null ? null : DashboardLayout::findLayout($this->getPanel()->getId(), static::getDashboardLayoutKey(), $user, $this->getDashboardLayoutTenantKey());
        $this->hasStoredDashboardLayout = $stored !== null;

        return $this->cachedDashboardLayout = $this->normalizeDashboardLayout($stored ?? []);
    }

    public function hasCustomDashboardLayout(): bool
    {
        $this->getDashboardLayout();

        return (bool) $this->hasStoredDashboardLayout;
    }

    /**
     * Clean a layout sent by the browser or read from the database (see `getDashboardLayout()`).
     *
     * @param  array<mixed>  $layout
     * @return array<int, array{id: string, span: int|null, hidden: bool}>
     */
    public function normalizeDashboardLayout(array $layout): array
    {
        $library = $this->getLibraryWidgets();
        $result = [];

        foreach ($layout as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;

            if (! is_string($id) || ! isset($library[$id]) || isset($result[$id])) {
                continue;
            }

            $span = $entry['span'] ?? null;
            $span = is_numeric($span) ? (int) $span : ($span === 'full' ? $this->getDashboardMaxColumns() : null);

            $result[$id] = [
                'id' => $id,
                'span' => $span !== null && in_array($span, $this->getWidgetSpanOptions($library[$id]), true) ? $span : null,
                'hidden' => (bool) ($entry['hidden'] ?? false),
            ];
        }

        foreach ($this->getDefaultDashboardLayout() as $entry) {
            $result[$entry['id']] ??= $entry;
        }

        return array_values($result);
    }

    /**
     * Save the user's layout; null resets it to the default.
     *
     * @param  array<int, array{id: string, span: int|null, hidden: bool}>|null  $layout
     */
    public function saveDashboardLayout(?array $layout): void
    {
        $user = $this->getDashboardLayoutUser();

        if ($user === null) {
            return;
        }

        $panel = $this->getPanel()->getId();
        $tenant = $this->getDashboardLayoutTenantKey();

        if ($layout === null) {
            DashboardLayout::forget($panel, static::getDashboardLayoutKey(), $user, $tenant);
        } else {
            DashboardLayout::store($panel, static::getDashboardLayoutKey(), $user, $tenant, $this->normalizeDashboardLayout($layout));
        }

        $this->cachedDashboardLayout = null;
        $this->hasStoredDashboardLayout = null;
        $this->cachedWidgets = null;
    }

    /**
     * The widgets shown, in the user's order and with their chosen spans.
     *
     * @return array<string, Widget>
     */
    protected function getVisibleLayoutWidgets(): array
    {
        $library = $this->getLibraryWidgets();
        $columns = $this->getDashboardGridColumns();
        $widgets = [];

        foreach ($this->getDashboardLayout() as $entry) {
            if ($entry['hidden'] || ! isset($library[$entry['id']])) {
                continue;
            }

            $widget = $library[$entry['id']];

            if ($entry['span'] !== null) {
                $widget->columnSpan($this->spanToColumnSpan($entry['span'], $columns));
            }

            $widgets[$entry['id']] = $widget;
        }

        return $widgets;
    }

    /**
     * The `Widgets` block of a customizable dashboard, with what the "Edit dashboard" mode
     * needs (`customize`).
     *
     * @return array<string, mixed>
     */
    protected function serializeCustomizableWidgets(): array
    {
        $columns = $this->getDashboardGridColumns();
        $visible = array_intersect_key($this->getAllWidgetInstances(), $this->getVisibleLayoutWidgets());
        $defaults = array_column($this->getDefaultDashboardLayout(), 'id');

        return [
            'type' => 'Widgets',
            'key' => 'widgets',
            'columns' => $columns,
            'widgets' => array_values(array_map(
                fn (Widget $widget): array => $this->rendersLazyPlaceholders() && $widget::isLazy() ? $widget->toLazySchema() : $widget->toSchema(),
                $visible,
            )),
            'customize' => [
                'endpoint' => $this->getPanel()->getEndpoints()['dashboardLayout'] ?? null,
                'token' => $this->getContextToken(),
                'customized' => $this->hasCustomDashboardLayout(),
                'layout' => $this->getDashboardLayout(),
                'defaultLayout' => $this->getDefaultDashboardLayout(),
                'library' => array_values(array_map(fn (Widget $widget): array => array_filter([
                    'id' => $widget->getId(),
                    'label' => $widget->getLibraryLabel(),
                    'description' => $widget->getLibraryDescription(),
                    'default' => in_array($widget->getId(), $defaults, true),
                    'span' => $this->getWidgetDefaultSpan($widget),
                    'spans' => array_map(fn (int $span): array => [
                        'value' => $span,
                        'label' => $span >= $this->getDashboardMaxColumns()
                            ? __('alfakit::panel.dashboard.customize.span_full')
                            : trans_choice('alfakit::panel.dashboard.customize.span', $span, ['count' => $span]),
                        'columnSpan' => $this->spanToColumnSpan($span, $columns),
                    ], $this->getWidgetSpanOptions($widget)),
                ], static fn (mixed $value): bool => $value !== null), $this->getLibraryWidgets())),
            ],
        ];
    }

    /**
     * @return array<string, int|null>
     */
    protected function getDashboardGridColumns(): array
    {
        $columns = $this->getColumns();

        return is_array($columns) ? $columns : ['default' => 1, 'lg' => $columns];
    }

    protected function getDashboardMaxColumns(): int
    {
        return max([1, ...array_values(array_map(intval(...), array_filter($this->getDashboardGridColumns(), is_numeric(...))))]);
    }

    /**
     * The spans the user may choose for a widget, as column counts.
     *
     * @return array<int, int>
     */
    protected function getWidgetSpanOptions(Widget $widget): array
    {
        $max = $this->getDashboardMaxColumns();
        $allowed = $widget->getAllowedColumnSpans() ?? range(1, $max);
        $spans = [];

        foreach ($allowed as $span) {
            $span = $span === 'full' ? $max : (is_numeric($span) ? (int) $span : null);

            if ($span !== null && $span >= 1 && $span <= $max) {
                $spans[$span] = $span;
            }
        }

        $spans[$this->getWidgetDefaultSpan($widget)] ??= $this->getWidgetDefaultSpan($widget);
        ksort($spans);

        return array_values($spans);
    }

    /**
     * The widget's own span at the grid's widest breakpoint, as a column count.
     */
    protected function getWidgetDefaultSpan(Widget $widget): int
    {
        $max = $this->getDashboardMaxColumns();
        $spans = $widget->getColumnSpan();
        $value = null;

        foreach (array_reverse(array_keys($this->getDashboardGridColumns())) as $breakpoint) {
            if (array_key_exists($breakpoint, $spans) && $spans[$breakpoint] !== null) {
                $value = $spans[$breakpoint];

                break;
            }
        }

        $value ??= $spans['default'] ?? 1;

        return $value === 'full' ? $max : max(1, min($max, is_numeric($value) ? (int) $value : 1));
    }

    /**
     * A span (column count) as a responsive `columnSpan`: the whole row where the grid has
     * fewer columns.
     *
     * @param  array<string, int|null>  $columns
     * @return array<string, int|string>
     */
    protected function spanToColumnSpan(int $span, array $columns): array
    {
        $result = [];

        foreach ($columns as $breakpoint => $count) {
            if ($count === null) {
                continue;
            }

            $result[$breakpoint] = $span >= (int) $count ? 'full' : $span;
        }

        return $result;
    }

    protected function isDefaultDashboardWidget(Widget $widget): bool
    {
        foreach ($this->getWidgets() as $default) {
            if ($default instanceof Widget ? $default === $widget : $default === $widget::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The key layouts of this dashboard are stored under.
     */
    public static function getDashboardLayoutKey(): string
    {
        return static::getSlug();
    }

    protected function getDashboardLayoutUser(): ?Authenticatable
    {
        return $this->getPanel()->auth()->user();
    }

    protected function getDashboardLayoutTenantKey(): ?string
    {
        $tenant = alfakit()->getTenant();

        return $tenant === null ? null : (string) $tenant->getKey();
    }

    protected function flushDashboardLayoutCaches(): void
    {
        $this->cachedLibraryWidgets = null;
        $this->cachedDashboardLayout = null;
        $this->hasStoredDashboardLayout = null;
    }
}
