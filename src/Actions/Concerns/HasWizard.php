<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use Closure;

/**
 * A modal form split into wizard steps: `->steps([Step::make('Details')->schema([...])])`.
 * The steps become the action's form (validated as one form on submit, the wizard jumps
 * to the first step with an error), and the modal's submit button only shows on the last
 * step.
 */
trait HasWizard
{
    /** @var array<Step>|Closure|null */
    protected array|Closure|null $steps = null;

    protected bool|Closure $areStepsSkippable = false;

    protected int|Closure $startStep = 1;

    /**
     * @param  array<Step>|Closure|null  $steps
     */
    public function steps(array|Closure|null $steps): static
    {
        $this->steps = $steps;
        $this->form = null;

        return $this;
    }

    /**
     * Let the user jump to any step instead of moving through them in order.
     */
    public function skippableSteps(bool|Closure $condition = true): static
    {
        $this->areStepsSkippable = $condition;

        return $this;
    }

    public function startOnStep(int|Closure $step): static
    {
        $this->startStep = $step;

        return $this;
    }

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return array_values($this->evaluate($this->steps) ?? []);
    }

    public function hasWizard(): bool
    {
        return $this->steps !== null && $this->getSteps() !== [];
    }

    /**
     * @return array<Wizard>
     */
    protected function getWizardFormComponents(): array
    {
        return [
            Wizard::make($this->getSteps())
                ->key('wizard')
                ->skippable(fn (): bool => (bool) $this->evaluate($this->areStepsSkippable))
                ->startOnStep(fn (): int => (int) $this->evaluate($this->startStep)),
        ];
    }
}
