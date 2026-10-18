<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Views;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Icons;
use AlfaSix\Alfakit\Support\Labels;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A view defined in code (`Table::views([...])`): a named table state shown as a tab (or
 * in the views dropdown) above the table. Every part is optional; parts a view leaves out
 * use the table's defaults.
 *
 * ```php
 * TableView::make('overdue')
 *     ->label('Overdue')
 *     ->icon('heroicon-o-clock')
 *     ->filters(['status' => ['value' => 'overdue']])
 *     ->sort('due_at', 'desc')
 *     ->columns(['title', 'due_at', 'customer.name'])
 *     ->default();
 * ```
 */
class TableView
{
    use EvaluatesClosures;

    protected string|Closure|null $label = null;

    protected string|BackedEnum|Htmlable|Closure|null $icon = null;

    /** @var array<string, array<string, mixed>>|Closure|null */
    protected array|Closure|null $filters = null;

    protected string|Closure|null $search = null;

    /** @var array{0: string, 1: string}|null */
    protected ?array $sort = null;

    /** @var array<string>|Closure|null */
    protected array|Closure|null $columns = null;

    /** @var array{0: string, 1: string|null}|null */
    protected ?array $group = null;

    protected int|string|Closure|null $perPage = null;

    protected bool|Closure $isDefault = false;

    protected bool|Closure $isHidden = false;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'view';
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getLabel(): string
    {
        $label = $this->evaluate($this->label);

        return is_string($label) && $label !== '' ? $label : Labels::fromName($this->name);
    }

    public function icon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->icon));
    }

    /**
     * Filter state by filter name, as in the `?filters[...]` query string:
     * `['status' => ['value' => 'overdue'], 'trashed' => ['value' => 0]]`.
     *
     * @param  array<string, array<string, mixed>>|Closure|null  $filters
     */
    public function filters(array|Closure|null $filters): static
    {
        $this->filters = $filters;

        return $this;
    }

    public function search(string|Closure|null $search): static
    {
        $this->search = $search;

        return $this;
    }

    public function sort(string $column, string $direction = 'asc'): static
    {
        $this->sort = [$column, strtolower($direction) === 'desc' ? 'desc' : 'asc'];

        return $this;
    }

    /**
     * The columns shown, in order. Toggleable columns left out are hidden; columns that
     * can't be toggled stay visible (after the listed ones).
     *
     * @param  array<string>|Closure|null  $columns
     */
    public function columns(array|Closure|null $columns): static
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * Group the records (`groups()` id); `''` turns a default group off.
     */
    public function group(string $group, ?string $direction = null): static
    {
        $this->group = [$group, $direction === null ? null : (strtolower($direction) === 'desc' ? 'desc' : 'asc')];

        return $this;
    }

    public function perPage(int|string|Closure|null $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Apply this view when the URL names none (users may pick their own default).
     */
    public function default(bool|Closure $condition = true): static
    {
        $this->isDefault = $condition;

        return $this;
    }

    public function isDefault(): bool
    {
        return (bool) $this->evaluate($this->isDefault);
    }

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition instanceof Closure ? fn (): bool => ! $this->evaluate($condition) : ! $condition;

        return $this;
    }

    public function isHidden(): bool
    {
        return (bool) $this->evaluate($this->isHidden);
    }

    /**
     * The view's table state, in the format saved views store (only the parts it sets).
     *
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $state = [];
        $filters = $this->evaluate($this->filters);
        $search = $this->evaluate($this->search);
        $columns = $this->evaluate($this->columns);
        $perPage = $this->evaluate($this->perPage);

        if (is_array($filters)) {
            $state['filters'] = $filters;
        }

        if (is_string($search)) {
            $state['search'] = $search;
        }

        if ($this->sort !== null) {
            $state['sort'] = ['column' => $this->sort[0], 'direction' => $this->sort[1]];
        }

        if (is_array($columns)) {
            $state['columns'] = array_values(array_filter($columns, is_string(...)));
        }

        if ($this->group !== null) {
            $state['group'] = ['id' => $this->group[0], 'direction' => $this->group[1]];
        }

        if ($perPage !== null) {
            $state['perPage'] = $perPage;
        }

        return $state;
    }
}
