<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Support\Debug;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Pages\Page;
use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Resources\RelationManagers\RelationManager;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Deprecations;
use AlfaSix\Alfakit\Tables\Filters\BaseFilter;
use AlfaSix\Alfakit\Tables\Table;
use AlfaSix\Alfakit\Widgets\Widget;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Builds the `alfakitDebug` page prop: the resolved schema tree with visibility and
 * authorisation per component, the closures that ran, and server timings. Also renders
 * that tree as text for `PageTester::dump()`.
 *
 * @phpstan-type DebugNode array{type: string, key?: string|null, label?: string|null, visible: bool, authorized?: bool|null, reason?: string|null, details?: array<string, mixed>, children?: list<array<string, mixed>>}
 */
final class SchemaDebugger
{
    /** Header a test may send to receive the debug prop while `runningUnitTests()`. */
    public const TEST_HEADER = 'X-Alfakit-Debug';

    private static bool $profiling = false;

    private static int $queries = 0;

    private static float $queryTime = 0.0;

    /**
     * Whether the page should carry the debug prop: the panel's debug panel is active, or a
     * test asked for it. Production responses (`app.debug` off) never include it.
     */
    public static function shouldInclude(Panel $panel, ?Request $request = null): bool
    {
        if ($panel->hasDebugPanel()) {
            return true;
        }

        return app()->runningUnitTests() && $request?->hasHeader(self::TEST_HEADER) === true;
    }

    /**
     * Start recording closures and database queries for this request.
     */
    public static function start(): void
    {
        ClosureRecorder::start();

        self::$queries = 0;
        self::$queryTime = 0.0;

        if (! self::$profiling) {
            self::$profiling = true;

            DB::listen(static function ($query): void {
                if (ClosureRecorder::$enabled) {
                    self::$queries++;
                    self::$queryTime += (float) $query->time;
                }
            });
        }
    }

    /**
     * The debug prop for a page whose props were just computed.
     *
     * @return array<string, mixed>
     */
    public static function collect(Page $page, ?Request $request = null): array
    {
        $tree = self::tree($page);
        $closures = ClosureRecorder::all();

        ClosureRecorder::stop();

        $startedAt = (float) ($request?->server('REQUEST_TIME_FLOAT') ?? (defined('LARAVEL_START') ? LARAVEL_START : microtime(true)));

        return [
            'page' => $page::class,
            'panel' => $page->getPanel()->getId(),
            'tree' => $tree,
            'closures' => $closures,
            'deprecations' => Deprecations::toDebug() ?? [],
            'server' => [
                'duration' => round((microtime(true) - $startedAt) * 1000, 1),
                'memory' => memory_get_peak_usage(true),
                'queries' => self::$queries,
                'queryTime' => round(self::$queryTime, 1),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
        ];
    }

    /**
     * The schema tree of a page: header actions, form, infolist, table, widgets and relation
     * managers, each with visibility and (where it applies) authorisation.
     *
     * @return list<array<string, mixed>>
     */
    public static function tree(Page $page): array
    {
        $tree = [];

        if (($actions = self::actions($page->getCachedHeaderActions())) !== []) {
            $tree[] = ['type' => 'HeaderActions', 'key' => 'headerActions', 'visible' => true, 'children' => $actions];
        }

        if (($form = $page->getForm()) !== null) {
            $tree[] = [
                'type' => 'Form',
                'key' => 'form',
                'visible' => true,
                'details' => ['operation' => $form->getOperation()],
                'children' => [...self::components($form), ...self::actions($page->getCachedFormActions())],
            ];
        }

        if (($infolist = $page->getInfolist()) !== null) {
            $tree[] = ['type' => 'Infolist', 'key' => 'infolist', 'visible' => true, 'children' => self::components($infolist)];
        }

        if (($table = $page->getTable()) !== null) {
            $tree[] = self::table($table, 'table');
        }

        foreach (['getHeaderWidgets' => 'headerWidgets', 'getFooterWidgets' => 'footerWidgets', 'getWidgets' => 'widgets'] as $method => $key) {
            if (($widgets = self::widgets($page, $method)) !== []) {
                $tree[] = ['type' => 'Widgets', 'key' => $key, 'visible' => true, 'children' => $widgets];
            }
        }

        if (($managers = self::relationManagers($page)) !== []) {
            $tree[] = ['type' => 'RelationManagers', 'key' => 'relationManagers', 'visible' => true, 'children' => $managers];
        }

        return $tree;
    }

    /**
     * Render a debug prop (or just its tree) as an indented text tree.
     *
     * @param  array<string, mixed>  $debug
     */
    public static function render(array $debug): string
    {
        $lines = [];

        if (isset($debug['page'])) {
            $lines[] = (string) $debug['page'];
        }

        $tree = $debug['tree'] ?? $debug;

        foreach (array_values(is_array($tree) ? $tree : []) as $index => $node) {
            if (is_array($node)) {
                self::renderNode($node, '', $index === count($tree) - 1, $lines);
            }
        }

        if (is_array($debug['closures'] ?? null) && $debug['closures'] !== []) {
            $lines[] = '';
            $lines[] = 'Closures evaluated:';

            foreach ($debug['closures'] as $closure) {
                $lines[] = sprintf('  %s  %s:%d  ×%d', $closure['owner'], $closure['file'], $closure['line'], $closure['count']);
            }
        }

        if (is_array($debug['server'] ?? null)) {
            $server = $debug['server'];
            $lines[] = '';
            $lines[] = sprintf('Server: %sms, %d queries (%sms), %s peak memory', $server['duration'], $server['queries'], $server['queryTime'], self::bytes((int) $server['memory']));
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $lines
     */
    private static function renderNode(array $node, string $prefix, bool $last, array &$lines): void
    {
        $flags = [];

        if (($node['visible'] ?? true) === false) {
            $flags[] = 'hidden'.(isset($node['reason']) ? ': '.$node['reason'] : '');
        }

        if (($node['authorized'] ?? null) === false) {
            $flags[] = 'unauthorized';
        }

        $label = $node['type'].(isset($node['key']) && $node['key'] !== '' ? ' '.$node['key'] : '');

        if (isset($node['label']) && $node['label'] !== '' && $node['label'] !== ($node['key'] ?? null)) {
            $label .= ' "'.$node['label'].'"';
        }

        foreach ((array) ($node['details'] ?? []) as $name => $value) {
            if ($value !== null && $value !== false && $value !== '') {
                $flags[] = $value === true ? (string) $name : "{$name}: ".(is_scalar($value) ? (string) $value : json_encode($value));
            }
        }

        $lines[] = $prefix.($last ? '└─ ' : '├─ ').$label.($flags !== [] ? '  ['.implode(', ', $flags).']' : '');

        $children = array_values((array) ($node['children'] ?? []));

        foreach ($children as $index => $child) {
            if (is_array($child)) {
                self::renderNode($child, $prefix.($last ? '   ' : '│  '), $index === count($children) - 1, $lines);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function components(Schema $schema): array
    {
        $nodes = [];

        foreach ($schema->getComponents(withHidden: true) as $component) {
            $nodes[] = self::component($component);
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function component(Component $component): array
    {
        $hidden = self::try(static fn (): bool => $component->isHidden(), true);

        $node = [
            'type' => $component->getType(),
            'key' => $component->getKey(),
            'label' => self::try(static fn (): ?string => $component->getLabel(), null),
            'visible' => ! $hidden,
            'reason' => $hidden ? self::try(static fn (): ?string => $component->getHiddenReason(), null) : null,
        ];

        $details = [];

        if (method_exists($component, 'getStatePath') && ($path = self::try(static fn (): ?string => $component->getStatePath(), null)) !== null && $path !== '') {
            $details['statePath'] = $path;
        }

        if (method_exists($component, 'isDisabled') && self::try(static fn (): bool => (bool) $component->isDisabled(), false)) {
            $details['disabled'] = true;
        }

        if ($details !== []) {
            $node['details'] = $details;
        }

        if ($component->hasChildComponents()) {
            $node['children'] = self::components($component->getChildSchema());
        }

        return array_filter($node, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<Action|ActionGroup>  $actions
     * @return list<array<string, mixed>>
     */
    private static function actions(array $actions): array
    {
        $nodes = [];

        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                $nodes[] = [
                    'type' => 'ActionGroup',
                    'key' => 'group',
                    'visible' => self::try(static fn (): bool => $action->isVisible(), false),
                    'children' => self::actions($action->getActions()),
                ];

                continue;
            }

            $authorized = self::try(static fn (): bool => $action->isAuthorized(), false);
            $visible = self::try(static fn (): bool => $action->isVisible(), false);

            $nodes[] = array_filter([
                'type' => 'Action',
                'key' => $action->getName(),
                'label' => self::try(static fn (): string => $action->getLabel(), null),
                'visible' => $visible,
                'authorized' => $authorized,
                'reason' => $visible ? null : ($authorized ? 'hidden' : 'unauthorized'),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(Table $table, string $key): array
    {
        $visible = array_keys(self::try(static fn (): array => $table->getVisibleColumns(), []));
        $children = [];

        $columns = [];

        foreach ($table->getColumns() as $column) {
            $hidden = self::try(static fn (): bool => $column->isHidden(), true);
            $toggledOff = ! $hidden && ! in_array($column->getName(), $visible, true);

            $columns[] = array_filter([
                'type' => $column->getType(),
                'key' => $column->getName(),
                'label' => self::try(static fn (): string => $column->getLabel(), null),
                'visible' => ! $hidden && ! $toggledOff,
                'reason' => $hidden ? 'hidden' : ($toggledOff ? 'toggled off' : null),
                'details' => self::try(static fn (): bool => $column->isToggleable(), false) ? ['toggleable' => true] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $children[] = ['type' => 'Columns', 'key' => 'columns', 'visible' => true, 'children' => $columns];

        $filters = [];

        /** @var array<BaseFilter> $tableFilters */
        $tableFilters = self::try(static fn (): array => $table->getFilters(), []);

        foreach ($tableFilters as $filter) {
            $filters[] = [
                'type' => class_basename($filter),
                'key' => $filter->getName(),
                'label' => self::try(static fn (): string => $filter->getLabel(), null),
                'visible' => ! self::try(static fn (): bool => $filter->isHidden(), true),
            ];
        }

        if ($filters !== []) {
            $children[] = ['type' => 'Filters', 'key' => 'filters', 'visible' => true, 'children' => $filters];
        }

        foreach (['getHeaderActions' => 'headerActions', 'getRecordActions' => 'recordActions', 'getBulkActions' => 'bulkActions', 'getEmptyStateActions' => 'emptyStateActions'] as $method => $actionsKey) {
            $actions = self::actions(self::try(static fn (): array => $table->{$method}(), []));

            if ($actions !== []) {
                $children[] = ['type' => 'Actions', 'key' => $actionsKey, 'visible' => true, 'children' => $actions];
            }
        }

        return ['type' => 'Table', 'key' => $key, 'visible' => true, 'children' => $children];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function widgets(Page $page, string $method): array
    {
        if (! method_exists($page, $method)) {
            return [];
        }

        /** @var array<class-string<Widget>|Widget> $widgets */
        $widgets = self::try(static fn (): array => (array) self::callProtected($page, $method), []);
        $nodes = [];

        foreach ($widgets as $widget) {
            $class = $widget instanceof Widget ? $widget::class : $widget;

            if (! is_a($class, Widget::class, true)) {
                continue;
            }

            $canView = self::try(static fn (): bool => $class::canView(), false);

            $nodes[] = array_filter([
                'type' => 'Widget',
                'key' => Widget::idFromClass($class),
                'label' => class_basename($class),
                'visible' => $canView,
                'authorized' => $canView,
                'reason' => $canView ? null : 'canView() returned false',
                'details' => $class::isLazy() ? ['lazy' => true] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function relationManagers(Page $page): array
    {
        if (! method_exists($page, 'getRelationManagerClasses') || ! method_exists($page, 'getRecord')) {
            return [];
        }

        $record = self::try(static fn () => $page->getRecord(), null);

        if ($record === null) {
            return [];
        }

        /** @var array<class-string<RelationManager>> $classes */
        $classes = self::try(static fn (): array => (array) self::callProtected($page, 'getRelationManagerClasses'), []);
        $nodes = [];

        foreach ($classes as $class) {
            if (! is_a($class, RelationManager::class, true)) {
                continue;
            }

            $canView = self::try(static fn (): bool => $class::canViewForRecord($record, $page::class), false);

            $nodes[] = array_filter([
                'type' => 'RelationManager',
                'key' => $class::getRelationshipName(),
                'label' => class_basename($class),
                'visible' => $canView,
                'authorized' => $canView,
                'reason' => $canView ? null : 'canViewForRecord() returned false',
            ], static fn (mixed $value): bool => $value !== null);
        }

        return $nodes;
    }

    private static function callProtected(object $target, string $method): mixed
    {
        return Closure::bind(fn (): mixed => $this->{$method}(), $target, $target::class)();
    }

    /**
     * Debug output must never break the page: a failing getter is reported as its fallback.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    private static function try(Closure $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }

    private static function bytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
