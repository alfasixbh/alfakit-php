<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Concerns;

use Closure;

/**
 * Reorder mode of a `reorderable()` table. Like Filament, reorder mode lists every record
 * (pagination off) so records can move between pages; `paginatedWhileReordering()` keeps
 * the pages. `beforeReordering()` / `afterReordering()` run around saving the new order
 * and receive `array $order` (the record keys in their new order).
 */
trait HasReorderingOptions
{
    protected bool|Closure $isPaginatedWhileReordering = false;

    protected ?Closure $beforeReordering = null;

    protected ?Closure $afterReordering = null;

    public function paginatedWhileReordering(bool|Closure $condition = true): static
    {
        $this->isPaginatedWhileReordering = $condition;

        return $this;
    }

    public function isPaginatedWhileReordering(): bool
    {
        return (bool) $this->evaluate($this->isPaginatedWhileReordering);
    }

    /**
     * Runs before the new order is saved: `fn (array $order) => ...`.
     */
    public function beforeReordering(?Closure $callback): static
    {
        $this->beforeReordering = $callback;

        return $this;
    }

    /**
     * Runs after the new order is saved: `fn (array $order) => ...`.
     */
    public function afterReordering(?Closure $callback): static
    {
        $this->afterReordering = $callback;

        return $this;
    }

    /**
     * Whether the table is in reorder mode (`?reordering=1`, set by the renderer's toggle).
     */
    public function isReordering(): bool
    {
        return $this->state->reordering && $this->isReorderable();
    }

    /**
     * Reorder mode lists every record unless `paginatedWhileReordering()`.
     */
    protected function isUnpaginatedForReordering(): bool
    {
        return $this->isReordering() && ! $this->isPaginatedWhileReordering();
    }

    /**
     * @param  array<int, string>  $order
     */
    protected function callReorderingHook(?Closure $hook, array $order): void
    {
        if ($hook !== null) {
            $this->evaluate($hook, ['order' => $order]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeReorderingOptions(): array
    {
        if (! $this->isReorderable()) {
            return [];
        }

        return array_filter([
            'reordering' => $this->isReordering() ?: null,
            'paginatedWhileReordering' => $this->isPaginatedWhileReordering() ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
