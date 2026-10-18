<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components\Concerns;

use AlfaSix\Alfakit\Support\Enums\Operation;
use Closure;

trait CanBeDisabled
{
    protected bool|Closure $isDisabled = false;

    /** @var array<string> */
    protected array $disabledOnOperations = [];

    public function disabled(bool|Closure $condition = true): static
    {
        $this->isDisabled = $condition;

        return $this;
    }

    /**
     * Disable for operations (`'edit'`, `Operation::Edit`) or a list of them.
     *
     * @param  string|Operation|array<string|Operation>  $operations
     */
    public function disabledOn(string|Operation|array $operations): static
    {
        $this->disabledOnOperations = Operation::normalize($operations);

        return $this;
    }

    public function isDisabled(): bool
    {
        if ($this->evaluate($this->isDisabled)) {
            return true;
        }

        $operation = method_exists($this, 'getOperation') ? $this->getOperation() : null;

        if ($operation !== null && in_array($operation, $this->disabledOnOperations, true)) {
            return true;
        }

        if (method_exists($this, 'getContainer') && $this->hasContainer()) {
            $parent = $this->getContainer()->getParentComponent();

            if ($parent !== null && method_exists($parent, 'isDisabled') && $parent->isDisabled()) {
                return true;
            }
        }

        return false;
    }
}
