<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Tables\Columns;

use Closure;

/**
 * A cell rendered by a frontend component the application registered with
 * `createAlfakit({ components: { StatusSwitcher } })`. The component receives the
 * column's `state`, the `viewData()` props and the cell.
 *
 * ```php
 * ViewColumn::make('status')->view('StatusSwitcher')->viewData(fn ($record) => ['id' => $record->id])
 * ```
 */
class ViewColumn extends Column
{
    protected string|Closure|null $view = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $viewData = [];

    /**
     * The name the frontend component is registered under.
     */
    public function view(string|Closure|null $view): static
    {
        $this->view = $view;

        return $this;
    }

    /**
     * Alias of `view()`.
     */
    public function component(string|Closure|null $component): static
    {
        return $this->view($component);
    }

    public function getView(): ?string
    {
        return $this->evaluate($this->view);
    }

    /**
     * Extra props for the component. A closure is evaluated per record (`$record`, `$state`).
     *
     * @param  array<string, mixed>|Closure  $data
     */
    public function viewData(array|Closure $data): static
    {
        $this->viewData = $data;

        return $this;
    }

    /**
     * Alias of `viewData()`.
     *
     * @param  array<string, mixed>|Closure  $props
     */
    public function props(array|Closure $props): static
    {
        return $this->viewData($props);
    }

    protected function getSchemaProps(): array
    {
        return [
            'component' => $this->getView(),
        ];
    }

    protected function getCellProps(): array
    {
        return [
            'props' => $this->evaluate($this->viewData) ?: null,
        ];
    }
}
