<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use AlfaSix\Alfakit\Tables\Columns\Column;
use Closure;

/**
 * Lets users reorder a table's columns from the column manager. The order is kept per
 * user in the session (the internal `table/columns` endpoint stores it), so it survives
 * page loads without touching the query string.
 *
 * @mixin \AlfaSix\Alfakit\Tables\Table
 */
trait CanReorderColumns
{
    protected bool|Closure $hasReorderableColumns = false;

    public function reorderableColumns(bool|Closure $condition = true): static
    {
        $this->hasReorderableColumns = $condition;

        return $this;
    }

    /**
     * Content layouts arrange columns themselves, so they can't be reordered.
     */
    public function hasReorderableColumns(): bool
    {
        return (bool) $this->evaluate($this->hasReorderableColumns) && ! $this->hasContentLayout();
    }

    /**
     * Session key identifying this table: the page / relation manager class, the query
     * string identifier (widgets) and the model.
     */
    public function getColumnOrderSessionKey(): string
    {
        $context = is_object($this->context) ? $this->context::class : (string) $this->context;
        $panel = alfakit()->getCurrentPanel()?->getId();

        return 'alfakit.tables.'.sha1(implode('|', [$panel, $context, $this->queryStringIdentifier, $this->getModel()])).'.columns';
    }

    /**
     * @return array<string>|null
     */
    public function getPersistedColumnOrder(): ?array
    {
        if (! $this->hasReorderableColumns() || ! app()->bound('session')) {
            return null;
        }

        $order = session()->get($this->getColumnOrderSessionKey());

        return is_array($order) ? array_values(array_filter($order, is_string(...))) : null;
    }

    /**
     * Store a new column order. Names that are not visible-capable columns of this table
     * are dropped; columns missing from the list keep their defined position at the end.
     *
     * @param  array<mixed>  $names
     * @return array<string> The stored order.
     */
    public function persistColumnOrder(array $names): array
    {
        abort_unless($this->hasReorderableColumns(), 404);

        $known = array_keys(array_filter($this->getColumns(), static fn (Column $column): bool => ! $column->isHidden()));
        $order = array_values(array_unique(array_filter($names, static fn (mixed $name): bool => is_string($name) && in_array($name, $known, true))));

        session()->put($this->getColumnOrderSessionKey(), $order);

        return $order;
    }

    public function resetColumnOrder(): void
    {
        session()->forget($this->getColumnOrderSessionKey());
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $columns
     * @return array<string, T>
     */
    protected function applyColumnOrder(array $columns): array
    {
        // A saved view may impose its own order (`HasSavedViews`).
        $order = $this->tableViewColumnOrder ?? $this->getPersistedColumnOrder();

        if ($order === null || $order === []) {
            return $columns;
        }

        $sorted = [];

        foreach ($order as $name) {
            if (array_key_exists($name, $columns)) {
                $sorted[$name] = $columns[$name];
            }
        }

        return $sorted + $columns;
    }
}
