<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Schema;
use Closure;

trait HasChildComponents
{
    /** @var array<Component>|Closure */
    protected array|Closure $childComponents = [];

    protected ?Schema $childSchema = null;

    /** The root state version `$childSchema` was built for, when the children come from a closure. */
    protected ?int $childSchemaStateVersion = null;

    /**
     * @param  array<Component>|Closure  $components
     */
    public function schema(array|Closure $components): static
    {
        $this->childComponents = $components;
        $this->childSchema = null;

        return $this;
    }

    /**
     * @param  array<Component>|Closure  $components
     */
    public function components(array|Closure $components): static
    {
        return $this->schema($components);
    }

    /**
     * @return array<Component>
     */
    public function getChildComponents(): array
    {
        return $this->evaluate($this->childComponents) ?? [];
    }

    public function hasChildComponents(): bool
    {
        // A `schema()` closure may be expensive: reuse the memoised child schema instead of
        // running it again on every traversal of the tree.
        if ($this->childComponents instanceof Closure) {
            return $this->getChildSchema()->getComponents(withHidden: true) !== [];
        }

        return $this->getChildComponents() !== [];
    }

    /**
     * The nested container holding this component's children. It shares the root schema's
     * state, record and operation; `$statePath` lets layout components (and Repeater
     * items) nest their children under a sub-path.
     */
    public function getChildSchema(?string $statePath = null): Schema
    {
        if ($this->childSchema !== null && $statePath === null && $this->childSchemaStateVersion === $this->getChildComponentsStateVersion()) {
            return $this->childSchema;
        }

        $components = $this->getChildComponents();

        if ($statePath !== null) {
            // Each item gets its own component instances so per-item state and closures never collide.
            $components = array_map(static fn (Component $component): Component => clone $component, $components);
        }

        $schema = Schema::make()
            ->parentComponent($this)
            ->components($components);

        if ($statePath !== null) {
            $schema->statePath($statePath);
        } elseif (method_exists($this, 'getStatePath') && filled($ownPath = $this->getStatePath(isAbsolute: true))) {
            $schema->statePath($ownPath);
        }

        if ($statePath === null) {
            $this->childSchema = $schema;
            $this->childSchemaStateVersion = $this->getChildComponentsStateVersion();
        }

        return $schema;
    }

    /**
     * Children from a closure (`schema(fn (Get $get) => …)`) depend on the state, so the
     * cached child schema is memoised per root state version: a live refresh applies the
     * browser's state (or a `$set()` changes it) and the closure runs again. Static arrays
     * are cached for the component's lifetime (`null`).
     */
    protected function getChildComponentsStateVersion(): ?int
    {
        if (! $this->childComponents instanceof Closure || ! $this->hasContainer()) {
            return null;
        }

        return $this->getContainer()->getStateVersion();
    }

    public function flushChildSchema(): static
    {
        $this->childSchema = null;

        return $this;
    }
}
