<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use Closure;
use Illuminate\Support\Str;

trait HasKey
{
    protected string|Closure|null $key = null;

    protected ?string $generatedKey = null;

    public function key(string|Closure|null $key): static
    {
        $this->key = $key;

        return $this;
    }

    /**
     * A stable identifier for the component within its container. Fields default to their
     * state path; layout components get a deterministic key based on type + position.
     */
    public function getKey(): string
    {
        $key = $this->evaluate($this->key);

        if ($key !== null) {
            return (string) $key;
        }

        if (method_exists($this, 'getStatePath') && filled($statePath = $this->getStatePath())) {
            return $statePath;
        }

        if ($this->generatedKey !== null) {
            return $this->generatedKey;
        }

        $generated = $this->generateKey();

        // Only remember keys derived from a real position in the tree.
        if (method_exists($this, 'hasContainer') && $this->hasContainer()) {
            $this->generatedKey = $generated;
        }

        return $generated;
    }

    /**
     * Derived from the component's place in the tree (parent key + type + index among its
     * siblings), so it stays the same across live refreshes and the renderer keeps state
     * such as collapsed sections, active tabs and focus.
     */
    protected function generateKey(): string
    {
        $type = Str::kebab(class_basename($this));

        if (! method_exists($this, 'hasContainer') || ! $this->hasContainer()) {
            return $type;
        }

        $container = $this->getContainer();
        $index = 0;

        foreach ($container->getComponents(withHidden: true) as $sibling) {
            if ($sibling === $this) {
                break;
            }

            if ($sibling::class === static::class) {
                $index++;
            }
        }

        $parent = $container->getParentComponent()?->getKey() ?? $container->getStatePath();

        return ($parent !== null && $parent !== '' ? $parent.'.' : '').$type.'-'.$index;
    }

    public function generatedKey(string $key): static
    {
        $this->generatedKey = $key;

        return $this;
    }
}
