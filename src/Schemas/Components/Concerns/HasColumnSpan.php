<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;

trait HasColumnSpan
{
    /** @var array<string, int|string|null>|int|string|Closure|null */
    protected array|int|string|Closure|null $columnSpan = null;

    /** @var array<string, int|string|null>|int|string|Closure|null */
    protected array|int|string|Closure|null $columnStart = null;

    /** @var array<string, int|null>|int|Closure|null */
    protected array|int|Closure|null $columnOrder = null;

    /**
     * Columns this component spans. A number applies from `lg` up; pass a breakpoint map
     * (`['default' => 2]`) to span on phones too.
     *
     * @param  array<string, int|string|null>|int|string|Closure|null  $span
     */
    public function columnSpan(array|int|string|Closure|null $span): static
    {
        $this->columnSpan = $span;

        return $this;
    }

    public function columnSpanFull(): static
    {
        return $this->columnSpan('full');
    }

    /**
     * The column this component starts at. A number applies from `lg` up, like `columnSpan()`.
     *
     * @param  array<string, int|string|null>|int|string|Closure|null  $start
     */
    public function columnStart(array|int|string|Closure|null $start): static
    {
        $this->columnStart = $start;

        return $this;
    }

    /**
     * The visual position of this component in its grid (CSS `order`), without changing
     * the order of the schema. A number applies at every size; pass a breakpoint map
     * (`['default' => 2, 'lg' => 1]`) to reorder per breakpoint.
     *
     * @param  array<string, int|null>|int|Closure|null  $order
     */
    public function columnOrder(array|int|Closure|null $order): static
    {
        $this->columnOrder = $order;

        return $this;
    }

    /**
     * @return array<string, int|null>|null
     */
    public function getColumnOrder(): ?array
    {
        $order = $this->evaluate($this->columnOrder);

        if ($order === null) {
            return null;
        }

        return is_array($order) ? $order : ['default' => (int) $order];
    }

    /**
     * @return array<string, int|string|null>|null
     */
    public function getColumnSpan(): ?array
    {
        return $this->normalizeBreakpoints($this->evaluate($this->columnSpan));
    }

    /**
     * @return array<string, int|string|null>|null
     */
    public function getColumnStart(): ?array
    {
        return $this->normalizeBreakpoints($this->evaluate($this->columnStart));
    }

    /**
     * Like Filament, a number applies from the `lg` breakpoint up (smaller screens stack
     * components in one column), `'full'` applies at every size and a breakpoint map is
     * used as given: `columnSpan(['default' => 2, 'md' => 1])`.
     *
     * @return array<string, int|string|null>|null
     */
    protected function normalizeBreakpoints(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if ($value === 'full') {
            return ['default' => 'full'];
        }

        return ['lg' => $value];
    }
}
