<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Infolists\Components;

use Closure;

/**
 * An entry rendered by a frontend component the application registered with
 * `createAlfakit({ components: { OrderTimeline } })`. The component receives the entry's
 * `state`, the `viewData()` props and the entry schema.
 *
 * ```php
 * ViewEntry::make('events')->view('OrderTimeline')->viewData(['compact' => true])
 * ```
 */
class ViewEntry extends Entry
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
     * Extra props for the component; closures receive `$record` and `$state`.
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
            'props' => $this->evaluate($this->viewData) ?: null,
        ];
    }
}
