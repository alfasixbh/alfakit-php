<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use Closure;

class Tabs extends Component
{
    use Concerns\CanDeferLoading {
        shouldRenderDeferredPlaceholder as protected shouldRenderOwnDeferredPlaceholder;
    }

    protected int|Closure $activeTab = 1;

    protected bool|Closure $isContained = false;

    protected bool|Closure $isVertical = false;

    protected bool|Closure $shouldPersistInQueryString = false;

    protected string|Closure $queryStringKey = 'tab';

    protected bool|Closure $isScrollable = true;

    protected bool|Closure $shouldPersistTab = false;

    protected string|Closure $persistTabStorage = 'local';

    public function __construct(string|Closure|null $label = null)
    {
        $this->label($label);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->columnSpanFull();
    }

    /**
     * @param  array<Tab>|Closure  $tabs
     */
    public function tabs(array|Closure $tabs): static
    {
        return $this->schema($tabs);
    }

    public function activeTab(int|Closure $tab): static
    {
        $this->activeTab = $tab;

        return $this;
    }

    public function contained(bool|Closure $condition = true): static
    {
        $this->isContained = $condition;

        return $this;
    }

    public function vertical(bool|Closure $condition = true): static
    {
        $this->isVertical = $condition;

        return $this;
    }

    public function persistTabInQueryString(string|Closure|null $key = 'tab'): static
    {
        $this->shouldPersistInQueryString = $key !== null;
        $this->queryStringKey = $key ?? 'tab';

        return $this;
    }

    /**
     * `false` wraps the tab list onto more lines instead of scrolling it sideways, so every
     * tab stays visible.
     */
    public function scrollable(bool|Closure $condition = true): static
    {
        $this->isScrollable = $condition;

        return $this;
    }

    /**
     * Remember the active tab in the browser, under the tabs' `id()` (or key): `local`
     * storage keeps it across visits, `session` storage until the browser tab closes. A
     * tab in the query string (`persistTabInQueryString()`) wins.
     */
    public function persistTab(bool|Closure $condition = true, string|Closure $storage = 'local'): static
    {
        $this->shouldPersistTab = $condition;
        $this->persistTabStorage = $storage;

        return $this;
    }

    public function shouldPersistTab(): bool
    {
        return (bool) $this->evaluate($this->shouldPersistTab);
    }

    public function getPersistTabKey(): string
    {
        $id = $this->getId();

        return 'alfakit.tabs.'.(filled($id) ? $id : $this->getKey());
    }

    public function getPersistTabStorage(): string
    {
        return $this->evaluate($this->persistTabStorage) === 'session' ? 'session' : 'local';
    }

    protected function getDefaultLabel(): ?string
    {
        return null;
    }

    /**
     * `deferLoading()` on the tabs defers each tab's content: the tab list is always sent.
     */
    public function shouldRenderDeferredPlaceholder(): bool
    {
        return false;
    }

    protected function getSchemaProps(): array
    {
        return [
            'activeTab' => $this->evaluate($this->activeTab),
            'contained' => $this->evaluate($this->isContained) ?: null,
            'vertical' => $this->evaluate($this->isVertical) ?: null,
            'queryStringKey' => $this->evaluate($this->shouldPersistInQueryString) ? $this->evaluate($this->queryStringKey) : null,
            'scrollable' => $this->evaluate($this->isScrollable) ? null : false,
            'persistTab' => $this->shouldPersistTab() ? ['key' => $this->getPersistTabKey(), 'storage' => $this->getPersistTabStorage()] : null,
        ];
    }
}
