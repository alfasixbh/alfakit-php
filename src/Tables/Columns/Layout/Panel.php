<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns\Layout;

use Closure;

/**
 * A muted box under a record's main content, for secondary details. Make it
 * `collapsible()` to hide the details behind a toggle on each record.
 *
 * ```php
 * Panel::make([
 *     Stack::make([
 *         TextColumn::make('phone')->icon('phone'),
 *         TextColumn::make('email')->icon('mail'),
 *     ]),
 * ])->collapsible()
 * ```
 */
class Panel extends Component
{
    protected bool|Closure $isCollapsible = false;

    protected bool|Closure $isCollapsed = true;

    public function collapsible(bool|Closure $condition = true): static
    {
        $this->isCollapsible = $condition;

        return $this;
    }

    /**
     * Whether a collapsible panel starts closed (the default) or open.
     */
    public function collapsed(bool|Closure $condition = true): static
    {
        $this->isCollapsed = $condition;

        return $this;
    }

    public function isCollapsible(): bool
    {
        return (bool) $this->evaluate($this->isCollapsible);
    }

    protected function getSchemaProps(): array
    {
        $collapsible = $this->isCollapsible();

        return [
            'collapsible' => $collapsible ?: null,
            'collapsed' => $collapsible && $this->evaluate($this->isCollapsed) ? true : null,
        ];
    }
}
