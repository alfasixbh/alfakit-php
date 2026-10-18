<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;

/**
 * Actions attached to a field or entry (`prefixActions()`, `suffixActions()`,
 * `hintActions()`, a Select's create/edit option actions) run in that component's
 * context: `$component` is the field, `$state` its state, and `$get` / `$set` read and
 * write the form the field belongs to. The modal form's own data stays in `$data`.
 */
trait InteractsWithFieldContext
{
    protected ?Component $fieldComponent = null;

    public function fieldComponent(?Component $component): static
    {
        $this->fieldComponent = $component;

        // Unless the action was pointed at a record or model of its own (a Select's create
        // option action targets the related model), it acts on the form's record.
        if ($component !== null && $this->record === null && $this->model === null) {
            $this->record = static fn () => $component->getRecord();
            $this->model = static fn () => $component->getModel();
        }

        return $this;
    }

    public function getFieldComponent(): ?Component
    {
        return $this->fieldComponent;
    }

    /**
     * @return array<mixed>|null
     */
    protected function resolveFieldContextDependencyByName(string $parameterName): ?array
    {
        $component = $this->fieldComponent;

        if ($component === null) {
            return null;
        }

        return match ($parameterName) {
            'component' => [$component],
            'get' => [$component->makeGetUtility()],
            'set' => [$component->makeSetUtility()],
            'state' => [method_exists($component, 'getState') ? $component->getState() : null],
            default => null,
        };
    }

    /**
     * @return array<mixed>|null
     */
    protected function resolveFieldContextDependencyByType(string $parameterType): ?array
    {
        $component = $this->fieldComponent;

        if ($component === null) {
            return null;
        }

        return match (true) {
            $parameterType === Get::class => [$component->makeGetUtility()],
            $parameterType === Set::class => [$component->makeSetUtility()],
            $component instanceof $parameterType => [$component],
            default => null,
        };
    }
}
