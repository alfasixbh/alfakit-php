<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Support\Enums\Operation;
use Closure;

trait CanBeHidden
{
    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    /** @var array<string> */
    protected array $hiddenOnOperations = [];

    /** @var array<string> */
    protected array $visibleOnOperations = [];

    public function hidden(bool|Closure $condition = true): static
    {
        $this->isHidden = $condition;

        return $this;
    }

    public function visible(bool|Closure $condition = true): static
    {
        $this->isVisible = $condition;

        return $this;
    }

    /**
     * Hide for operations (`'edit'`, `Operation::Edit`) or owners (`RelationManager::class`,
     * `EditRecord::class`).
     *
     * @param  string|Operation|array<string|Operation>  $operations
     */
    public function hiddenOn(string|Operation|array $operations): static
    {
        $this->hiddenOnOperations = Operation::normalize($operations);

        return $this;
    }

    /**
     * @param  string|Operation|array<string|Operation>  $operations
     */
    public function visibleOn(string|Operation|array $operations): static
    {
        $this->visibleOnOperations = Operation::normalize($operations);

        return $this;
    }

    /**
     * Why the component is hidden (`hidden()`, `visible(false)`, `hiddenOn()`/`visibleOn()`
     * for the current operation), or null when it is visible. Used by the debug panel.
     */
    public function getHiddenReason(): ?string
    {
        if ($this->evaluate($this->isHidden)) {
            return 'hidden()';
        }

        if (! $this->evaluate($this->isVisible)) {
            return 'visible(false)';
        }

        if ($this->matchesOn($this->hiddenOnOperations)) {
            return 'hiddenOn('.implode(', ', $this->hiddenOnOperations).')';
        }

        if ($this->visibleOnOperations !== [] && $this->matchesOn($this->visibleOnOperations) === false && $this->canCheckOn($this->visibleOnOperations)) {
            return 'visibleOn('.implode(', ', $this->visibleOnOperations).')';
        }

        return null;
    }

    public function isHidden(): bool
    {
        if ($this->evaluate($this->isHidden)) {
            return true;
        }

        if (! $this->evaluate($this->isVisible)) {
            return true;
        }

        if ($this->matchesOn($this->hiddenOnOperations)) {
            return true;
        }

        if ($this->visibleOnOperations !== [] && ! $this->matchesOn($this->visibleOnOperations) && $this->canCheckOn($this->visibleOnOperations)) {
            return true;
        }

        return false;
    }

    /**
     * Whether one of the entries applies here: an operation name (`create`, `edit`, `view`)
     * equal to the schema's operation, or a class name (`RelationManager::class`,
     * `EditRecord::class`) that the schema's owner is an instance of.
     *
     * @param  array<string>  $entries
     */
    protected function matchesOn(array $entries): bool
    {
        $operation = method_exists($this, 'getOperation') ? $this->getOperation() : null;
        $owner = $this->getOnOwner();

        foreach ($entries as $entry) {
            if (self::isOnClass($entry)) {
                if ($owner !== null && $owner instanceof $entry) {
                    return true;
                }
            } elseif ($operation !== null && $entry === $operation) {
                return true;
            }
        }

        return false;
    }

    /**
     * `visibleOn()` only hides when it can tell: an operation is known for operation
     * entries, or the entries name classes (an unknown owner is then "not that class").
     *
     * @param  array<string>  $entries
     */
    protected function canCheckOn(array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::isOnClass($entry)) {
                return true;
            }
        }

        return method_exists($this, 'getOperation') && $this->getOperation() !== null;
    }

    protected function getOnOwner(): ?object
    {
        return method_exists($this, 'hasContainer') && $this->hasContainer() ? $this->getContainer()->getOwner() : null;
    }

    private static function isOnClass(string $entry): bool
    {
        return str_contains($entry, '\\') && (class_exists($entry) || interface_exists($entry));
    }

    public function isVisible(): bool
    {
        return ! $this->isHidden();
    }

    /**
     * Hidden itself, or inside a hidden section, tab, step or other layout component.
     */
    public function isHiddenInTree(): bool
    {
        if ($this->isHidden()) {
            return true;
        }

        if (method_exists($this, 'hasContainer') && $this->hasContainer()) {
            $parent = $this->getContainer()->getParentComponent();

            if ($parent !== null && $parent->isHiddenInTree()) {
                return true;
            }
        }

        return false;
    }
}
