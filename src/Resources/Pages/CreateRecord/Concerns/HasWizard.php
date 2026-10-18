<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Resources\Pages\CreateRecord\Concerns;

use AlfaSix\Alfakit\Schemas\Components\Wizard;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Schemas\Schema;

/**
 * A Create page whose form is a wizard (Filament's `HasWizard`):
 *
 * ```php
 * class CreateOrder extends CreateRecord
 * {
 *     use HasWizard;
 *
 *     protected function getSteps(): array
 *     {
 *         return [
 *             Step::make('Customer')->schema([...]),
 *             Step::make('Items')->schema([...]),
 *         ];
 *     }
 * }
 * ```
 *
 * Each "Next" validates its step on the server; the page's form buttons (create, create
 * another, cancel) show on the last step only.
 */
trait HasWizard
{
    /**
     * @return array<Step>
     */
    protected function getSteps(): array
    {
        return [];
    }

    /** The step (1-based) the wizard opens on. */
    public function getStartStep(): int
    {
        return 1;
    }

    /** Whether the user may jump to any step without completing the ones before it. */
    public function hasSkippableSteps(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([$this->getWizardComponent()]);
    }

    protected function getWizardComponent(): Wizard
    {
        return Wizard::make($this->getSteps())
            ->startOnStep($this->getStartStep())
            ->skippable($this->hasSkippableSteps());
    }

    /**
     * The form buttons wait for the wizard's last step.
     */
    protected function hasWizardForm(): bool
    {
        return true;
    }
}
