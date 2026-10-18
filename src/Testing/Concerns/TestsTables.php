<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Testing\Concerns;

use AlfaSix\Alfakit\Actions\Action;
use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;

/**
 * The page's table: search, sort and filter it through the query string (as the
 * renderer does), assert on the records it shows, and run record and bulk actions.
 */
trait TestsTables
{
    /** @var array<string, mixed> Table state sent as the page's query string */
    protected array $tableState = [];

    /** The table the helpers work on: `table` (the page's), or `relationManager:{class}`. */
    protected string $tableComponent = 'table';

    /**
     * Work on a relation manager's table (Edit and View pages): searching, sorting,
     * filtering, record and bulk actions and record assertions all go to it. Pass null to
     * go back to the page's own table.
     *
     * @param  class-string|null  $relationManager
     */
    public function relationManager(?string $relationManager): static
    {
        $this->tableComponent = $relationManager === null ? 'table' : 'relationManager:'.ltrim($relationManager, '\\');

        if ($relationManager !== null) {
            $this->loadLazyRelationManagers();
            $this->requireTable();
        }

        return $this;
    }

    public function getTableComponent(): string
    {
        return $this->tableComponent;
    }

    public function searchTable(?string $search = null): static
    {
        $this->setTableState('search', $search === '' ? null : $search);

        return $this->load();
    }

    /**
     * Search one column (`->searchable(isIndividual: true)`).
     */
    public function searchTableColumns(array $searches): static
    {
        $this->setTableState('columnSearch', array_filter($searches, static fn (mixed $value): bool => $value !== null && $value !== '') ?: null);

        return $this->load();
    }

    public function sortTable(?string $column = null, ?string $direction = 'asc'): static
    {
        $this->setTableState('sort', $column);
        $this->setTableState('direction', $column === null ? null : ($direction === 'desc' ? 'desc' : 'asc'));

        return $this->load();
    }

    /**
     * Apply a filter. `$data` is the filter form's state, or a single value:
     * `filterTable('is_published', true)`, `filterTable('status', 'draft')`,
     * `filterTable('featured')` for a checkbox filter, `filterTable('tags', [1, 2])`.
     */
    public function filterTable(string $name, mixed $data = null): static
    {
        $filters = (array) ($this->tableState[$this->tableStateKey('filters')] ?? []);
        $filters[$name] = $this->filterState($name, $data);

        $this->setTableState('filters', $filters);

        return $this->load();
    }

    public function removeTableFilter(string $name): static
    {
        $filters = (array) ($this->tableState[$this->tableStateKey('filters')] ?? []);
        // An explicit empty state overrides the filter's default.
        $filters[$name] = [$this->filterFieldName($name) => ''];

        $this->setTableState('filters', $filters);

        return $this->load();
    }

    /**
     * Clear every filter (including filters with a default), as "Remove all" does.
     */
    public function removeTableFilters(): static
    {
        $filters = [];

        foreach ((array) ($this->requireTable()['filters'] ?? []) as $filter) {
            if (is_array($filter) && is_string($filter['name'] ?? null)) {
                $filters[$filter['name']] = [$this->filterFieldName($filter['name']) => ''];
            }
        }

        $this->setTableState('filters', $filters ?: null);

        return $this->load();
    }

    /**
     * Show (`true`) or hide every toggleable column, as the column manager's "select all".
     */
    public function toggleAllTableColumns(bool $condition = true): static
    {
        $names = [];

        foreach ((array) ($this->requireTable()['columns'] ?? []) as $column) {
            if (is_array($column) && ($column['toggleable'] ?? false) && is_string($column['name'] ?? null)) {
                $names[] = $column['name'];
            }
        }

        // `?columns=` (empty) means "no toggleable column shown".
        $this->setTableState('columns', $condition ? ($names ?: '') : '');

        return $this->load();
    }

    public function resetTableFilters(): static
    {
        $this->setTableState('filters', null);

        return $this->load();
    }

    public function setTablePage(int $page): static
    {
        $this->setTableState('page', $page > 1 ? $page : null);

        return $this->load();
    }

    public function setTablePerPage(int|string $perPage): static
    {
        $this->setTableState('perPage', $perPage);

        return $this->load();
    }

    // ---------------------------------------------------------------- Records

    /**
     * @param  iterable<Model|int|string>  $records
     */
    public function assertCanSeeTableRecords(iterable $records, bool $inOrder = false): static
    {
        $visible = $this->getTableRecordKeys();
        $expected = $this->normalizeKeys($records);

        foreach ($expected as $key) {
            Assert::assertContains($key, $visible, "Failed asserting that the table shows the record [{$key}]. Visible: ".json_encode($visible));
        }

        if ($inOrder) {
            $positions = array_map(static fn (string $key): int|false => array_search($key, $visible, true), $expected);
            $sorted = $positions;
            sort($sorted);

            Assert::assertSame($sorted, $positions, 'Failed asserting that the table shows the records in the given order. Visible: '.json_encode($visible));
        }

        return $this;
    }

    /**
     * @param  iterable<Model|int|string>  $records
     */
    public function assertCanNotSeeTableRecords(iterable $records): static
    {
        $visible = $this->getTableRecordKeys();

        foreach ($this->normalizeKeys($records) as $key) {
            Assert::assertNotContains($key, $visible, "Failed asserting that the table does not show the record [{$key}].");
        }

        return $this;
    }

    /**
     * The total number of records matching the table's search and filters (all pages).
     */
    public function assertCountTableRecords(int $count): static
    {
        $table = $this->requireTable();
        $total = $table['pagination']['total'] ?? null;

        Assert::assertSame($count, is_int($total) ? $total : count((array) ($table['records'] ?? [])));

        return $this;
    }

    /**
     * The keys of the records on the current page of the table, in display order.
     *
     * @return array<int, string>
     */
    public function getTableRecordKeys(): array
    {
        return array_values(array_map(
            static fn (array $record): string => (string) ($record['key'] ?? ''),
            array_filter((array) ($this->requireTable()['records'] ?? []), is_array(...)),
        ));
    }

    /**
     * The serialised table block (`columns`, `records`, `pagination`…).
     *
     * @return array<string, mixed>
     */
    public function getTable(): array
    {
        return $this->requireTable();
    }

    public function assertTableColumnExists(string $name): static
    {
        Assert::assertContains($name, $this->getTableColumnNames(), "Failed asserting that the table has a visible column [{$name}].");

        return $this;
    }

    public function assertTableColumnDoesNotExist(string $name): static
    {
        Assert::assertNotContains($name, $this->getTableColumnNames(), "Failed asserting that the table has no visible column [{$name}].");

        return $this;
    }

    /**
     * The column can be rendered: it exists and isn't hidden (it may be toggled off).
     */
    public function assertCanRenderTableColumn(string $name): static
    {
        Assert::assertContains($name, $this->getRenderableTableColumnNames(), "Failed asserting that the table can render the column [{$name}].");

        return $this;
    }

    public function assertCanNotRenderTableColumn(string $name): static
    {
        Assert::assertNotContains($name, $this->getRenderableTableColumnNames(), "Failed asserting that the table can not render the column [{$name}].");

        return $this;
    }

    /**
     * The column is shown right now (not hidden, not toggled off).
     */
    public function assertTableColumnVisible(string $name): static
    {
        return $this->assertTableColumnExists($name);
    }

    /**
     * The column can be rendered but is toggled off (or is hidden).
     */
    public function assertTableColumnHidden(string $name): static
    {
        Assert::assertNotContains($name, $this->getTableColumnNames(), "Failed asserting that the table column [{$name}] is hidden.");

        return $this;
    }

    /**
     * The column's state for a record, before formatting (`getState()`), compared with
     * `assertEquals`. Use `assertTableColumnFormattedStateSet()` for what the cell shows.
     */
    public function assertTableColumnStateSet(string $column, mixed $state, Model|int|string $record): static
    {
        Assert::assertEquals($state, $this->getTableColumnRawState($column, $record), "Failed asserting the state of the table column [{$column}].");

        return $this;
    }

    public function assertTableColumnStateNotSet(string $column, mixed $state, Model|int|string $record): static
    {
        Assert::assertNotEquals($state, $this->getTableColumnRawState($column, $record), "Failed asserting that the table column [{$column}] does not have the state.");

        return $this;
    }

    /**
     * What the cell shows for a record (after `formatStateUsing()`, money, dates…).
     */
    public function assertTableColumnFormattedStateSet(string $column, mixed $state, Model|int|string $record): static
    {
        Assert::assertEquals($state, $this->requireTableCell($column, $record)['state'] ?? null, "Failed asserting the formatted state of the table column [{$column}].");

        return $this;
    }

    public function assertTableColumnFormattedStateNotSet(string $column, mixed $state, Model|int|string $record): static
    {
        Assert::assertNotEquals($state, $this->requireTableCell($column, $record)['state'] ?? null, "Failed asserting that the table column [{$column}] does not show the state.");

        return $this;
    }

    /**
     * A text column's description for a record, `below` (default) or `above` the state.
     */
    public function assertTableColumnHasDescription(string $column, string $description, Model|int|string $record, string $position = 'below'): static
    {
        Assert::assertTrue($this->tableColumnHasDescription($column, $description, $record, $position), "Failed asserting that the table column [{$column}] has the description [{$description}] {$position} its state.");

        return $this;
    }

    public function assertTableColumnDoesNotHaveDescription(string $column, string $description, Model|int|string $record, string $position = 'below'): static
    {
        Assert::assertFalse($this->tableColumnHasDescription($column, $description, $record, $position), "Failed asserting that the table column [{$column}] does not have the description [{$description}] {$position} its state.");

        return $this;
    }

    /**
     * The cell has these attributes (`extraAttributes()` / `extraCellAttributes()`).
     * Classes are compared class by class.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assertTableColumnHasExtraAttributes(string $column, array $attributes, Model|int|string $record): static
    {
        Assert::assertTrue($this->cellHasAttributes($column, $attributes, $record), "Failed asserting that the table column [{$column}] has the attributes ".json_encode($attributes).'.');

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assertTableColumnDoesNotHaveExtraAttributes(string $column, array $attributes, Model|int|string $record): static
    {
        Assert::assertFalse($this->cellHasAttributes($column, $attributes, $record), "Failed asserting that the table column [{$column}] does not have the attributes ".json_encode($attributes).'.');

        return $this;
    }

    /**
     * A select column offers these options (`value => label`) for a record.
     *
     * @param  array<int|string, string>  $options
     */
    public function assertTableSelectColumnHasOptions(string $column, array $options, Model|int|string $record): static
    {
        $offered = $this->getTableSelectColumnOptions($column, $record);

        foreach ($options as $value => $label) {
            Assert::assertSame($label, $offered[(string) $value] ?? null, "Failed asserting that the select column [{$column}] offers [{$value} => {$label}].");
        }

        return $this;
    }

    /**
     * @param  array<int|string, string>  $options
     */
    public function assertTableSelectColumnDoesNotHaveOptions(string $column, array $options, Model|int|string $record): static
    {
        $offered = $this->getTableSelectColumnOptions($column, $record);

        foreach ($options as $value => $label) {
            Assert::assertNotSame($label, $offered[(string) $value] ?? null, "Failed asserting that the select column [{$column}] does not offer [{$value} => {$label}].");
        }

        return $this;
    }

    /**
     * The filter exists (visible or hidden); a closure receives the filter and returns
     * whether it is as expected.
     *
     * @param  (Closure(\AlfaSix\Alfakit\Tables\Filters\BaseFilter): bool)|null  $checkFilterUsing
     */
    public function assertTableFilterExists(string $name, ?Closure $checkFilterUsing = null): static
    {
        $this->withServerTable(function (\AlfaSix\Alfakit\Tables\Table $table) use ($name, $checkFilterUsing): void {
            $filter = $this->getAllServerFilters($table)[$name] ?? null;

            Assert::assertNotNull($filter, "Failed asserting that the table has a filter [{$name}].");

            if ($checkFilterUsing !== null) {
                Assert::assertTrue((bool) $checkFilterUsing($filter), "Failed asserting that the table filter [{$name}] passes the check.");
            }
        });

        return $this;
    }

    public function assertTableFilterVisible(string $name): static
    {
        Assert::assertContains($name, $this->getTableFilterNames(), "Failed asserting that the table filter [{$name}] is visible.");

        return $this;
    }

    /**
     * The filter exists but is hidden (`hidden()` / `visible(false)`).
     */
    public function assertTableFilterHidden(string $name): static
    {
        $this->assertTableFilterExists($name);

        Assert::assertNotContains($name, $this->getTableFilterNames(), "Failed asserting that the table filter [{$name}] is hidden.");

        return $this;
    }

    /**
     * A summary of a column for every record matching the table's filters and search, or
     * for the current page only. `$summarizerId` is the summarizer's id
     * (`Sum::make('total')`), or its class basename in lower case (`sum`, `average`,
     * `count`, `range`). A range compares `[min, max]`.
     */
    public function assertTableColumnSummarySet(string $column, string $summarizerId, mixed $state, bool $isCurrentPaginationPageOnly = false): static
    {
        $actual = $this->withServerTable(function (\AlfaSix\Alfakit\Tables\Table $table) use ($column, $summarizerId, $isCurrentPaginationPageOnly): mixed {
            $tableColumn = $table->getColumn($column);

            Assert::assertNotNull($tableColumn, "The table has no column [{$column}].");

            $summarizer = null;

            foreach ($tableColumn->getSummarizers() as $candidate) {
                if ($candidate->getId() === $summarizerId || strtolower(class_basename($candidate)) === strtolower($summarizerId)) {
                    $summarizer = $candidate;

                    break;
                }
            }

            Assert::assertNotNull($summarizer, "The table column [{$column}] has no summarizer [{$summarizerId}].");

            $query = $table->getFilteredSortedQuery()?->reorder();

            Assert::assertNotNull($query, 'The table has no query to summarise.');

            if ($isCurrentPaginationPageOnly) {
                $query->whereKey($table->getRecords()->map(static fn (Model $record): mixed => $record->getKey())->all());
            }

            return $summarizer->getState($query);
        });

        Assert::assertEquals($state, $actual, "Failed asserting the [{$summarizerId}] summary of the table column [{$column}].");

        return $this;
    }

    // ---------------------------------------------------------------- Actions

    /**
     * @param  string|class-string<Action>|array<int, string>  $name
     * @param  array<string, mixed>  $arguments
     */
    public function mountTableAction(string|array $name, Model|int|string|null $record = null, array $arguments = []): static
    {
        return $this->mountActionFor($this->tableComponent, $this->resolveActionName($name), $this->recordKey($record), null, false, $arguments);
    }

    /**
     * Run a record action (with `$record`) or a header action of the table.
     *
     * @param  string|class-string<Action>|array<int, string>  $name
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    public function callTableAction(string|array $name, Model|int|string|null $record = null, array $data = [], array $arguments = []): static
    {
        $name = $this->resolveActionName($name);
        $key = $this->recordKey($record);
        $schema = $this->findMountedChildAction($name) ?? $this->findTableAction($name, $key);

        if ($schema !== null && ! ($schema['hasModal'] ?? false) && $data === []) {
            return $this->runAction($this->tableComponent, $name, $key, null, false, $arguments, []);
        }

        $this->mountActionFor($this->tableComponent, $name, $key, null, false, $arguments);

        return $this->mountedAction === null ? $this : $this->fillForm($data)->callMountedAction();
    }

    /**
     * @param  string|class-string<Action>|array<int, string>  $name
     * @param  iterable<Model|int|string>  $records
     * @param  array<string, mixed>  $arguments
     */
    public function mountTableBulkAction(string|array $name, iterable $records, array $arguments = []): static
    {
        return $this->mountActionFor($this->tableComponent, $this->resolveActionName($name), null, $this->normalizeKeys($records), false, $arguments);
    }

    /**
     * Run a bulk action on the given records, or on every record matching the table's
     * search and filters with `$records = null` ("select all").
     *
     * @param  string|class-string<Action>|array<int, string>  $name
     * @param  iterable<Model|int|string>|null  $records
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $arguments
     */
    public function callTableBulkAction(string|array $name, ?iterable $records, array $data = [], array $arguments = []): static
    {
        $name = $this->resolveActionName($name);
        $keys = $records === null ? null : $this->normalizeKeys($records);
        $schema = $this->findTableBulkAction($name);

        if ($schema !== null && ! ($schema['hasModal'] ?? false) && $data === []) {
            return $this->runAction($this->tableComponent, $name, null, $keys ?? [], $records === null, $arguments, []);
        }

        $this->mountActionFor($this->tableComponent, $name, null, $keys ?? [], $records === null, $arguments);

        return $this->mountedAction === null ? $this : $this->fillForm($data)->callMountedAction();
    }

    public function assertTableActionVisible(string|array $name, Model|int|string|null $record = null): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNotNull($this->findTableAction($name, $this->recordKey($record)), "Failed asserting that the table action [{$name}] is visible.");

        return $this;
    }

    public function assertTableActionExists(string|array $name, Model|int|string|null $record = null): static
    {
        return $this->assertTableActionVisible($name, $record);
    }

    public function assertTableActionHidden(string|array $name, Model|int|string|null $record = null): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNull($this->findTableAction($name, $this->recordKey($record)), "Failed asserting that the table action [{$name}] is hidden.");

        return $this;
    }

    public function assertTableActionDoesNotExist(string|array $name, Model|int|string|null $record = null): static
    {
        return $this->assertTableActionHidden($name, $record);
    }

    public function assertTableActionEnabled(string|array $name, Model|int|string|null $record = null): static
    {
        $name = $this->resolveActionName($name);
        $action = $this->findTableAction($name, $this->recordKey($record));

        Assert::assertNotNull($action, "Failed asserting that the table action [{$name}] is visible.");
        Assert::assertFalse((bool) ($action['disabled'] ?? false), "Failed asserting that the table action [{$name}] is enabled.");

        return $this;
    }

    public function assertTableActionDisabled(string|array $name, Model|int|string|null $record = null): static
    {
        $name = $this->resolveActionName($name);
        $action = $this->findTableAction($name, $this->recordKey($record));

        Assert::assertNotNull($action, "Failed asserting that the table action [{$name}] is visible.");
        Assert::assertTrue((bool) ($action['disabled'] ?? false), "Failed asserting that the table action [{$name}] is disabled.");

        return $this;
    }

    public function assertTableBulkActionVisible(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNotNull($this->findTableBulkAction($name), "Failed asserting that the bulk action [{$name}] is visible.");

        return $this;
    }

    public function assertTableBulkActionExists(string|array $name): static
    {
        return $this->assertTableBulkActionVisible($name);
    }

    public function assertTableBulkActionHidden(string|array $name): static
    {
        $name = $this->resolveActionName($name);

        Assert::assertNull($this->findTableBulkAction($name), "Failed asserting that the bulk action [{$name}] is hidden.");

        return $this;
    }

    public function assertTableBulkActionDoesNotExist(string|array $name): static
    {
        return $this->assertTableBulkActionHidden($name);
    }

    // ---------------------------------------------------------------- Internals

    /**
     * Run code against the server-side table, resolved the way the internal endpoints
     * resolve it from the page token (same user, panel, tenant and table state), for
     * assertions on things the browser never receives (raw state, hidden filters,
     * summarizer values).
     *
     * @template TReturn
     *
     * @param  Closure(\AlfaSix\Alfakit\Tables\Table): TReturn  $callback
     * @return TReturn
     */
    protected function withServerTable(Closure $callback): mixed
    {
        $context = \AlfaSix\Alfakit\Support\SchemaContext::fromToken($this->getToken());
        $manager = alfakit();
        $previousPanel = $manager->getCurrentPanel();
        $previousTenant = $manager->getTenant();
        $previousRequest = app('request');
        $panel = $manager->getPanel($context->panel);

        $request = \Illuminate\Http\Request::create($this->getUrlWithTableState());
        $request->setUserResolver($previousRequest->getUserResolver());
        app()->instance('request', $request);
        $manager->setCurrentPanel($panel);

        try {
            $tenantKey = $context->parameters['tenant'] ?? null;

            if ($panel->hasTenancy() && $tenantKey !== null) {
                $manager->setTenant($panel->findTenantFor($tenantKey, $panel->auth()->user()), isQuiet: true);
            }

            /** @var class-string<\AlfaSix\Alfakit\Pages\Page> $class */
            $class = $context->page;
            $page = $class::fromContext($context);

            $table = match (true) {
                $this->tableComponent === 'table' => $page->getTable(),
                method_exists($page, 'getRelationManager') => $page->getRelationManager(substr($this->tableComponent, strlen('relationManager:')))?->getTable(),
                default => null,
            };

            Assert::assertInstanceOf(\AlfaSix\Alfakit\Tables\Table::class, $table, 'The page has no table.');

            return $callback($table);
        } finally {
            app()->instance('request', $previousRequest);
            $manager->setCurrentPanel($previousPanel);
            $manager->setTenant($previousTenant, isQuiet: true);
        }
    }

    /**
     * Every filter of a table, hidden ones included.
     *
     * @return array<string, \AlfaSix\Alfakit\Tables\Filters\BaseFilter>
     */
    protected function getAllServerFilters(\AlfaSix\Alfakit\Tables\Table $table): array
    {
        /** @var array<int, \AlfaSix\Alfakit\Tables\Filters\BaseFilter> $filters */
        $filters = (fn (): array => [...array_values($this->evaluate($this->filters) ?? []), ...$this->pushedFilters])->call($table);
        $named = [];

        foreach ($filters as $filter) {
            $named[$filter->getName()] = $filter;
        }

        return $named;
    }

    protected function getTableColumnRawState(string $column, Model|int|string $record): mixed
    {
        $key = (string) $this->recordKey($record);

        return $this->withServerTable(static function (\AlfaSix\Alfakit\Tables\Table $table) use ($column, $key): mixed {
            $tableColumn = $table->getColumn($column);

            Assert::assertNotNull($tableColumn, "The table has no column [{$column}].");

            $model = $table->getRecord($key);

            Assert::assertNotNull($model, "The table has no record [{$key}].");

            // Aggregate columns (`counts()`, `sum()`…) read an attribute the table query adds.
            if ($model instanceof Model && method_exists($tableColumn, 'hasRelationshipAggregate') && $tableColumn->hasRelationshipAggregate()) {
                $model = $tableColumn->applyRelationshipAggregate($model->newQuery())->whereKey($model->getKey())->first() ?? $model;
            }

            $tableColumn->record($model);

            try {
                return $tableColumn->getState();
            } finally {
                $tableColumn->record(null);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireTableCell(string $column, Model|int|string $record): array
    {
        $row = $this->findTableRecord($record);

        Assert::assertNotNull($row, "The table does not show the record [{$this->recordKey($record)}].");

        $cell = $row['cells'][$column] ?? null;

        Assert::assertIsArray($cell, "Failed asserting that the table shows the column [{$column}] for the record.");

        return $cell;
    }

    protected function tableColumnHasDescription(string $column, string $description, Model|int|string $record, string $position): bool
    {
        $cell = $this->requireTableCell($column, $record);
        $schema = collect((array) ($this->requireTable()['columns'] ?? []))->first(static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['name'] ?? null) === $column);
        $actualPosition = is_array($schema) && is_string($schema['descriptionPosition'] ?? null) ? $schema['descriptionPosition'] : 'below';

        return ($cell['description'] ?? null) === $description && $actualPosition === $position;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function cellHasAttributes(string $column, array $attributes, Model|int|string $record): bool
    {
        $actual = (array) ($this->requireTableCell($column, $record)['extraAttributes'] ?? []);

        foreach ($attributes as $name => $value) {
            if ($name === 'class') {
                $classes = preg_split('/\s+/', trim((string) ($actual['class'] ?? ''))) ?: [];

                foreach (preg_split('/\s+/', trim((string) $value)) ?: [] as $class) {
                    if ($class !== '' && ! in_array($class, $classes, true)) {
                        return false;
                    }
                }

                continue;
            }

            if (! array_key_exists($name, $actual) || $actual[$name] != $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    protected function getTableSelectColumnOptions(string $column, Model|int|string $record): array
    {
        $this->requireTableCell($column, $record);

        $schema = collect((array) ($this->requireTable()['columns'] ?? []))->first(static fn (mixed $candidate): bool => is_array($candidate) && ($candidate['name'] ?? null) === $column);
        $cell = (array) ($this->findTableRecord($record)['cells'][$column] ?? []);
        $options = [];

        // Per-record options (a closure) come with the cell, static ones with the column.
        foreach ((array) ($cell['options'] ?? (is_array($schema) ? ($schema['options'] ?? []) : [])) as $option) {
            if (! is_array($option)) {
                continue;
            }

            foreach (isset($option['options']) && is_array($option['options']) ? $option['options'] : [$option] as $item) {
                if (is_array($item) && array_key_exists('value', $item)) {
                    $options[(string) $item['value']] = (string) ($item['label'] ?? '');
                }
            }
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    protected function getTableFilterNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $filter): ?string => is_array($filter) && is_string($filter['name'] ?? null) ? $filter['name'] : null,
            (array) ($this->requireTable()['filters'] ?? []),
        )));
    }

    /**
     * @return array<int, string>
     */
    protected function getRenderableTableColumnNames(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $column): ?string => is_array($column) && is_string($column['name'] ?? null) ? $column['name'] : null,
            (array) ($this->requireTable()['columns'] ?? []),
        )));
    }

    protected function setTableState(string $key, mixed $value): void
    {
        $name = $key;
        $key = $this->tableStateKey($name);

        if ($value === null) {
            unset($this->tableState[$key]);
        } else {
            $this->tableState[$key] = $value;
        }

        // Searching, sorting and filtering start again on the first page.
        if ($name !== 'page') {
            unset($this->tableState[$this->tableStateKey('page')]);
        }
    }

    /**
     * The query-string key of a table state value: relation manager tables (and other
     * tables with a query-string identifier) prefix it (`comments-relation-manager_search`).
     */
    protected function tableStateKey(string $name): string
    {
        if ($this->tableComponent === 'table') {
            return $name;
        }

        $identifier = $this->requireTable()['identifier'] ?? null;

        return is_string($identifier) && $identifier !== '' ? "{$identifier}_{$name}" : $name;
    }

    /**
     * After a page load, a lazy relation manager being tested is loaded again.
     */
    protected function loadRelationManagerTable(): void
    {
        if ($this->tableComponent !== 'table') {
            $this->loadLazyRelationManagers();
        }
    }

    /**
     * Lazy relation managers arrive in the `alfakitLazy` deferred prop; load it as the
     * renderer does right after the page.
     */
    protected function loadLazyRelationManagers(): void
    {
        $block = $this->getContentBlock('relationManagers');

        if ($block === null || ! collect((array) ($block['managers'] ?? []))->contains(static fn (mixed $manager): bool => is_array($manager) && ($manager['lazy'] ?? false) === true)) {
            return;
        }

        $component = $this->pageResponse->json('component');

        if (! is_string($component)) {
            return;
        }

        $response = $this->getPageResponseFor($this->getUrlWithTableState(), [
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => \AlfaSix\Alfakit\Pages\Page::LAZY_PROP,
        ]);

        $lazy = $response->getStatusCode() === 200 ? $response->json('props.'.\AlfaSix\Alfakit\Pages\Page::LAZY_PROP) : null;

        if (! is_array($lazy)) {
            return;
        }

        $content = (array) ($this->props['page']['content'] ?? []);

        foreach ($content as $index => $contentBlock) {
            if (! is_array($contentBlock) || ($contentBlock['type'] ?? null) !== 'RelationManagers') {
                continue;
            }

            foreach ((array) ($contentBlock['managers'] ?? []) as $position => $manager) {
                $key = is_array($manager) ? ($manager['component'] ?? null) : null;

                if (is_string($key) && is_array($lazy[$key] ?? null)) {
                    $content[$index]['managers'][$position] = $lazy[$key];
                }
            }
        }

        $this->props['page']['content'] = $content;
    }

    protected function getUrlWithTableState(): string
    {
        if ($this->tableState === []) {
            return $this->url;
        }

        return $this->url.(str_contains($this->url, '?') ? '&' : '?').http_build_query($this->tableState);
    }

    /**
     * Deferred tables (`deferLoading()`) send their records in a partial reload, as the
     * renderer asks for them right after the page loads.
     */
    protected function loadDeferredTable(): void
    {
        $table = $this->getContentBlock('table');

        if ($table === null || array_key_exists('records', $table) && $table['records'] !== null) {
            return;
        }

        $component = $this->pageResponse->json('component');

        if (! is_string($component)) {
            return;
        }

        $response = $this->getPageResponseFor($this->getUrlWithTableState(), [
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => 'page',
        ]);

        if ($response->getStatusCode() === 200 && is_array($page = $response->json('props.page'))) {
            $this->props['page'] = $page;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterState(string $name, mixed $data): array
    {
        if (is_array($data) && ! array_is_list($data)) {
            return $data;
        }

        $field = $this->filterFieldName($name);

        if ($field === 'isActive') {
            return ['isActive' => $data === null || $data === true || $data === 1 || $data === '1' ? '1' : '0'];
        }

        if (is_bool($data)) {
            $data = $data ? '1' : '0';
        }

        if ($field === 'values') {
            return ['values' => array_values(array_map(static fn (mixed $value): string => $value instanceof Model ? (string) $value->getKey() : (string) $value, (array) $data))];
        }

        return [$field => $data instanceof Model ? $data->getKey() : $data];
    }

    /**
     * The state key of a filter's (first) form field: `isActive`, `value`, `values`…
     */
    protected function filterFieldName(string $name): string
    {
        $filter = collect((array) ($this->requireTable()['filters'] ?? []))->first(static fn (mixed $filter): bool => is_array($filter) && ($filter['name'] ?? null) === $name);

        Assert::assertIsArray($filter, "The table has no filter [{$name}].");

        return (string) ($filter['schema'][0]['name'] ?? 'value');
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireTable(): array
    {
        if ($this->tableComponent !== 'table') {
            return $this->requireRelationManagerTable();
        }

        $table = $this->getContentBlock('table');

        Assert::assertIsArray($table, "The page [{$this->url}] has no table.");

        return $table;
    }

    /**
     * @return array<string, mixed>
     */
    protected function requireRelationManagerTable(): array
    {
        $block = $this->getContentBlock('relationManagers');

        foreach ((array) ($block['managers'] ?? []) as $manager) {
            if (is_array($manager) && ($manager['component'] ?? null) === $this->tableComponent && is_array($manager['table'] ?? null)) {
                return $manager['table'];
            }
        }

        Assert::fail("The page [{$this->url}] shows no relation manager [".substr($this->tableComponent, strlen('relationManager:')).'] (is it registered in getRelations() and may the user view it?).');
    }

    /**
     * @return array<int, string>
     */
    protected function getTableColumnNames(): array
    {
        $table = $this->requireTable();
        $visible = $table['visibleColumns'] ?? null;

        if (is_array($visible)) {
            return array_values(array_map(strval(...), $visible));
        }

        return array_values(array_map(static fn (array $column): string => (string) ($column['name'] ?? ''), array_filter((array) ($table['columns'] ?? []), is_array(...))));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findTableRecord(Model|int|string $record): ?array
    {
        $key = (string) $this->recordKey($record);

        foreach ((array) ($this->requireTable()['records'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['key'] ?? '') === $key) {
                return $row;
            }
        }

        return null;
    }

    /**
     * A record action of the given record, or a header action of the table.
     *
     * @return array<string, mixed>|null
     */
    protected function findTableAction(string $name, int|string|null $record): ?array
    {
        $root = explode(Action::PATH_SEPARATOR, $name)[0];

        if ($record !== null) {
            $row = $this->findTableRecord($record);

            Assert::assertNotNull($row, "The table does not show the record [{$record}].");

            return $this->findActionInTree((array) ($row['actions'] ?? []), $root);
        }

        $table = $this->requireTable();

        return $this->findActionInTree([(array) ($table['headerActions'] ?? []), (array) ($table['emptyState'] ?? [])], $root);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function findTableBulkAction(string $name): ?array
    {
        return $this->findActionInTree((array) ($this->requireTable()['bulkActions'] ?? []), explode(Action::PATH_SEPARATOR, $name)[0]);
    }

    /**
     * @param  iterable<Model|int|string>  $records
     * @return array<int, string>
     */
    protected function normalizeKeys(iterable $records): array
    {
        $keys = [];

        foreach ($records as $record) {
            $keys[] = (string) ($record instanceof Model ? $record->getKey() : $record);
        }

        return $keys;
    }
}
