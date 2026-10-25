<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Widgets;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use AlfaSix\Alfakit\Support\Serializable;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;

/**
 * A Chart.js chart. Return a Chart.js-compatible `data` array from `getData()`.
 *
 * ```php
 * protected function getData(): array
 * {
 *     return ['datasets' => [['label' => 'Orders', 'data' => [12, 19, 3]]], 'labels' => ['Jan', 'Feb', 'Mar']];
 * }
 * ```
 */
abstract class ChartWidget extends Widget
{
    /** Query-string key of the `filtersSchema()` values: `chartFilters[{id}][field]`. */
    public const FILTERS_QUERY_KEY = 'chartFilters';

    protected ?string $heading = null;

    protected ?string $description = null;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = null;

    protected ?string $filter = null;

    /** @var array<string, mixed>|null */
    protected ?array $options = null;

    /**
     * Theme colour (`primary`, `success`, `info`…) of datasets that set no colours of
     * their own.
     */
    protected ?string $color = null;

    /** Whether the user can collapse the chart to its header. */
    protected bool $isCollapsible = false;

    /** `filtersSchema()` filters apply when the user clicks "Apply", not on every change. */
    protected bool $hasDeferredFilters = false;

    /**
     * Values of the `filtersSchema()` filters (with `HasFiltersSchema`), read in `getData()`.
     *
     * @var array<string, mixed>
     */
    public array $filters = [];

    protected ?string $emptyStateHeading = null;

    protected ?string $emptyStateDescription = null;

    protected ?string $emptyStateIcon = null;

    /**
     * A frontend component (registered with `createAlfakit({ components })`) shown in place
     * of the default empty state; it receives `getEmptyStateViewData()` as props.
     */
    protected ?string $emptyStateView = null;

    /** @var array<string, Action>|null */
    private ?array $cachedEmptyStateActions = null;

    /** @var array<string, mixed>|null */
    private ?array $cachedData = null;

    private ?Schema $resolvedFiltersSchema = null;

    /**
     * `line`, `bar`, `pie`, `doughnut`, `radar`, `polarArea`, `bubble` or `scatter`.
     */
    abstract protected function getChartType(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function getData(): array;

    protected function getHeading(): ?string
    {
        return $this->heading;
    }

    protected function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * An accessible alternative to the canvas for screen readers: text or HTML (a table of
     * the values, say). It is visually hidden and linked to the chart with
     * `aria-describedby`.
     */
    protected function getChartAssistiveContent(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    /**
     * Optional filter dropdown (`value => label`). The active value is in `$this->filter`.
     *
     * @return array<string, string>|null
     */
    protected function getFilters(): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function getOptions(): ?array
    {
        return $this->options;
    }

    protected function getPollingInterval(): ?string
    {
        return $this->pollingInterval;
    }

    protected function getMaxHeight(): ?string
    {
        return $this->maxHeight;
    }

    public function getFilter(): ?string
    {
        if ($this->filter !== null) {
            return $this->filter;
        }

        $requested = request()->query('widgetFilters.'.$this->getId()) ?? data_get(static::pageQuery('widgetFilters'), $this->getId());
        $filters = $this->getFilters();

        if ($filters !== null && $requested !== null && array_key_exists($requested, $filters)) {
            return $this->filter = (string) $requested;
        }

        return $this->filter = $filters !== null ? (string) array_key_first($filters) : null;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function isCollapsible(): bool
    {
        return $this->isCollapsible;
    }

    public function hasDeferredFilters(): bool
    {
        return $this->hasDeferredFilters;
    }

    // ---------------------------------------------------------------- Empty state

    /**
     * The chart data, computed once per request.
     *
     * @return array<string, mixed>
     */
    protected function getCachedData(): array
    {
        return $this->cachedData ??= $this->getData();
    }

    /**
     * Whether to show the empty state instead of the chart: when `getData()` returns an
     * empty array. Override to check e.g. `$this->getCachedData()['datasets'][0]['data']`.
     */
    public function isEmpty(): bool
    {
        return $this->getCachedData() === [];
    }

    public function getEmptyStateHeading(): ?string
    {
        return $this->emptyStateHeading;
    }

    public function getEmptyStateDescription(): ?string
    {
        return $this->emptyStateDescription;
    }

    public function getEmptyStateIcon(): ?string
    {
        return $this->emptyStateIcon;
    }

    /**
     * Buttons under the empty state's text (`CreateAction`, a link to import data…). They
     * run through the widget (`widget:<id>`), with `$record` the page's record.
     *
     * @return array<Action|\AlfaSix\Alfakit\Actions\ActionGroup>
     */
    protected function getEmptyStateActions(): array
    {
        return [];
    }

    /**
     * The registered frontend component rendered in place of the default empty state.
     */
    public function getEmptyStateView(): ?string
    {
        return $this->emptyStateView;
    }

    /**
     * Props of the `getEmptyStateView()` component.
     *
     * @return array<string, mixed>
     */
    protected function getEmptyStateViewData(): array
    {
        return [];
    }

    /**
     * The empty-state actions, prepared with the page as context and its record.
     *
     * @return array<Action|\AlfaSix\Alfakit\Actions\ActionGroup>
     */
    public function getPreparedEmptyStateActions(): array
    {
        $actions = $this->getEmptyStateActions();

        foreach ($actions as $action) {
            $action->context($this->page ?? $this);

            if ($this->record !== null) {
                $action->record($this->record);
            }
        }

        return $actions;
    }

    /**
     * An empty-state action by name (groups are searched too), as the action endpoint
     * resolves it for `widget:<id>`.
     */
    public function getAction(string $name): ?Action
    {
        if ($this->cachedEmptyStateActions === null) {
            $this->cachedEmptyStateActions = [];

            foreach ($this->getPreparedEmptyStateActions() as $action) {
                foreach ($action instanceof \AlfaSix\Alfakit\Actions\ActionGroup ? $action->getFlatActions() : [$action] as $flat) {
                    $this->cachedEmptyStateActions[$flat->getName()] = $flat;
                }
            }
        }

        return $this->cachedEmptyStateActions[$name] ?? null;
    }

    // ---------------------------------------------------------------- Filters form

    /**
     * The filters form (`HasFiltersSchema`), filled from the query string; null without one.
     */
    protected function makeFiltersSchema(): ?Schema
    {
        return null;
    }

    public function getFiltersSchema(): ?Schema
    {
        if ($this->resolvedFiltersSchema !== null) {
            return $this->resolvedFiltersSchema;
        }

        $schema = $this->makeFiltersSchema();

        if ($schema === null) {
            return null;
        }

        $schema->fill();
        $defaults = $schema->getRawState();
        $requested = static::pageQuery(self::FILTERS_QUERY_KEY);
        $requested = is_array($requested) ? ($requested[$this->getId()] ?? null) : null;

        if (is_array($requested) && $requested !== []) {
            $schema->applyClientState([...$defaults, ...array_intersect_key($requested, $defaults)]);

            try {
                $schema->validate();
            } catch (ValidationException) {
                // The query string is user input: invalid values fall back to the defaults.
                $schema->rawState($defaults);
            }
        }

        $this->filters = $schema->getState();

        return $this->resolvedFiltersSchema = $schema;
    }

    public function getType(): string
    {
        return 'Chart';
    }

    /**
     * A key of the page's query string. Internal endpoints (a starter kit dashboard's or a
     * standalone page's widgets, loaded from `/_alfakit/standalone/components`) don't share the
     * page URL; the renderer sends it as `tableQuery`, so read the key from there too.
     */
    protected static function pageQuery(string $key): mixed
    {
        $value = request()->query($key);

        if ($value !== null) {
            return $value;
        }

        $query = request()->input('tableQuery');

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str(ltrim($query, '?'), $parameters);

        return $parameters[$key] ?? null;
    }

    /**
     * Restyle the "Apply" button of deferred `filtersSchema()` filters. Override and return
     * the prepared action with another label, icon, colour, variant, size, tooltip… (or
     * `->hidden()`); the widget still decides what clicking it does.
     *
     * ```php
     * public function filtersApplyAction(Action $action): Action
     * {
     *     return $action->label('Update chart')->icon('refresh-cw');
     * }
     * ```
     */
    public function filtersApplyAction(Action $action): Action
    {
        return $action;
    }

    /**
     * Restyle the button that resets the `filtersSchema()` filters (see `filtersApplyAction()`).
     */
    public function filtersResetAction(Action $action): Action
    {
        return $action;
    }

    /**
     * The look of the filter buttons the widget customises (`filtersApplyAction()`,
     * `filtersResetAction()`), keyed like a table's `triggerActions`. Buttons that were not
     * customised are left out and keep the renderer's default look.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function serializeFiltersTriggerActions(): array
    {
        $triggers = [
            'filtersApply' => ['filtersApplyAction', 'applyFilters', __('alfakit::tables.filters.apply'), Color::Primary, true],
            'filtersReset' => ['filtersResetAction', 'resetFilters', __('alfakit::tables.filters.reset'), Color::Gray, false],
        ];

        $schema = [];

        foreach ($triggers as $key => [$method, $name, $label, $color, $isButton]) {
            // Only overridden methods change the look.
            if ((new ReflectionMethod($this, $method))->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            $action = Action::make($name)->label($label)->color($color);
            $action = $isButton ? $action->button() : $action->ghost();
            $action = $this->{$method}($action);

            if (! $action->isVisible()) {
                $schema[$key] = ['hidden' => true];

                continue;
            }

            $action = $action->toSchema();

            $schema[$key] = Serializable::compact([
                'label' => $action['label'] ?? null,
                'icon' => $action['icon'] ?? null,
                'iconPosition' => $action['iconPosition'] ?? null,
                'color' => $action['color'] ?? null,
                'variant' => $action['variant'] ?? null,
                'outlined' => $action['outlined'] ?? null,
                'size' => $action['size'] ?? null,
                'badge' => $action['badge'] ?? null,
                'badgeColor' => $action['badgeColor'] ?? null,
                'tooltip' => $action['tooltip'] ?? null,
                'disabled' => $action['disabled'] ?? null,
                'extraAttributes' => $action['extraAttributes'] ?? null,
            ]);
        }

        return $schema;
    }

    protected function getSchemaProps(): array
    {
        $this->getFilter();
        $filters = $this->getFilters();
        $filtersSchema = $this->getFiltersSchema();
        $empty = $this->isEmpty();

        return [
            'chartType' => $this->getChartType(),
            'heading' => $this->getHeading(),
            'description' => $this->getDescription(),
            'assistiveContent' => $empty ? null : \AlfaSix\Alfakit\Support\Html::from($this->getChartAssistiveContent()),
            'data' => $empty ? [] : $this->getCachedData(),
            'options' => $this->getOptions(),
            'filters' => $filters === null ? null : collect($filters)->map(static fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
            'filter' => $this->filter,
            'pollingInterval' => $this->getPollingInterval(),
            'maxHeight' => $this->getMaxHeight(),
            'color' => $this->getColor(),
            'collapsible' => $this->isCollapsible() ?: null,
            'filtersForm' => $filtersSchema === null ? null : [
                ...$filtersSchema->toPayload(),
                'deferred' => $this->hasDeferredFilters() ?: null,
                'queryKey' => self::FILTERS_QUERY_KEY,
                'triggerActions' => $this->serializeFiltersTriggerActions() ?: null,
            ],
            // `empty` shows the empty state; its texts are optional (the renderer has a default heading).
            'empty' => $empty ?: null,
            'emptyState' => $empty ? $this->serializeEmptyState() : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeEmptyState(): ?array
    {
        $view = $this->getEmptyStateView();
        $actions = collect($this->getPreparedEmptyStateActions())
            ->filter(static fn (\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup $action): bool => $action->isVisible())
            ->map(static fn (\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup $action): array => $action->toSchema())
            ->values()
            ->all();

        return array_filter([
            'heading' => $this->getEmptyStateHeading(),
            'description' => $this->getEmptyStateDescription(),
            'icon' => $this->getEmptyStateIcon(),
            'actions' => $actions ?: null,
            'view' => $view,
            'viewData' => $view !== null ? ($this->getEmptyStateViewData() ?: null) : null,
        ], static fn (mixed $value): bool => $value !== null) ?: null;
    }
}
