<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Schemas\Components;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Schemas\Components\Wizard\Step;
use AlfaSix\Alfakit\Support\Html;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;

/**
 * Multi-step form. Each step is validated server-side before advancing, via the
 * schema refresh endpoint, so the renderer never duplicates validation logic.
 */
class Wizard extends Component
{
    use Concerns\CanDeferLoading {
        shouldRenderDeferredPlaceholder as protected shouldRenderOwnDeferredPlaceholder;
    }
    use Concerns\PreparesActions;

    protected int|Closure $startStep = 1;

    protected bool|Closure $isSkippable = false;

    protected string|Closure|null $submitActionLabel = null;

    protected string|Closure|null $nextActionLabel = null;

    protected string|Closure|null $previousActionLabel = null;

    /** The query-string key the current step is kept under; null when not persisted. */
    protected string|bool|Closure|null $persistStepInQueryString = null;

    protected Action|Htmlable|Closure|null $submitAction = null;

    protected ?Closure $modifyNextActionUsing = null;

    protected ?Closure $modifyPreviousActionUsing = null;

    /** The step key `goToStep()` asked the browser to show, sent with the next response. */
    protected ?string $requestedStep = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $completedIcon = null;

    /**
     * @param  array<Step>|Closure  $steps
     */
    public function __construct(array|Closure $steps = [])
    {
        $this->steps($steps);
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->hiddenLabel();
        $this->columnSpanFull();
    }

    /**
     * @param  array<Step>|Closure  $steps
     */
    public function steps(array|Closure $steps): static
    {
        return $this->schema($steps);
    }

    public function startOnStep(int|Closure $step): static
    {
        $this->startStep = $step;

        return $this;
    }

    public function skippable(bool|Closure $condition = true): static
    {
        $this->isSkippable = $condition;

        return $this;
    }

    public function submitActionLabel(string|Closure|null $label): static
    {
        $this->submitActionLabel = $label;

        return $this;
    }

    public function nextActionLabel(string|Closure|null $label): static
    {
        $this->nextActionLabel = $label;

        return $this;
    }

    public function previousActionLabel(string|Closure|null $label): static
    {
        $this->previousActionLabel = $label;

        return $this;
    }

    /**
     * Keep the current step in the URL (`?step=2`) so a reload or a shared link opens on it.
     * Pass a key to use another parameter (two wizards on one page); `false` turns it off.
     */
    public function persistStepInQueryString(string|bool|Closure|null $key = 'step'): static
    {
        $this->persistStepInQueryString = $key;

        return $this;
    }

    public function getStepQueryStringKey(): ?string
    {
        $key = $this->evaluate($this->persistStepInQueryString);

        return match (true) {
            $key === true => 'step',
            is_string($key) && $key !== '' => $key,
            default => null,
        };
    }

    /**
     * Move the browser to a step from the server, e.g. in a field's `afterStateUpdated()`
     * or a schema action inside the wizard. `$step` is the step's `key()` (or the slug of
     * its label). The renderer switches step when the form state comes back; a wizard
     * that is not `skippable()` only moves back to an earlier or the current step.
     */
    public function goToStep(string $step): void
    {
        foreach ($this->getChildSchema()->getComponents() as $wizardStep) {
            if ($wizardStep->getKey() === $step || Str::slug((string) $wizardStep->getLabel()) === $step) {
                $this->requestedStep = $wizardStep->getKey();

                return;
            }
        }
    }

    public function getRequestedStep(): ?string
    {
        return $this->requestedStep;
    }

    /**
     * What the last step shows instead of "Next": an `Action` (rendered as a button that runs
     * through the action endpoint, like section actions) or `Htmlable` markup, such as
     * `new HtmlString('<button type="submit">Place order</button>')`.
     */
    public function submitAction(Action|Htmlable|Closure|null $action): static
    {
        $this->submitAction = $action;

        return $this;
    }

    public function getSubmitAction(): Action|Htmlable|null
    {
        $action = $this->evaluate($this->submitAction);

        return $action instanceof Action || $action instanceof Htmlable ? $action : null;
    }

    /**
     * Customise the "Next" button: `->nextAction(fn (Action $action) => $action->label('Continue')->icon('arrow-right'))`.
     * The button still validates the step and moves on; only its appearance (label, icon,
     * colour, size, visibility) is taken from the action.
     */
    public function nextAction(?Closure $callback): static
    {
        $this->modifyNextActionUsing = $callback;

        return $this;
    }

    /**
     * Customise the "Back" button, like `nextAction()`.
     */
    public function previousAction(?Closure $callback): static
    {
        $this->modifyPreviousActionUsing = $callback;

        return $this;
    }

    /**
     * The icon of steps the user has completed (default: a check mark). Steps can set
     * their own with `Step::completedIcon()`.
     */
    public function completedIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->completedIcon = $icon;

        return $this;
    }

    public function getCompletedIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->completedIcon));
    }

    public function getNextAction(): Action
    {
        return $this->makeNavigationAction('next', $this->evaluate($this->nextActionLabel) ?? __('alfakit::forms.wizard.next'), $this->modifyNextActionUsing);
    }

    public function getPreviousAction(): Action
    {
        return $this->makeNavigationAction('previous', $this->evaluate($this->previousActionLabel) ?? __('alfakit::forms.wizard.previous'), $this->modifyPreviousActionUsing);
    }

    protected function makeNavigationAction(string $name, string $label, ?Closure $modifyUsing): Action
    {
        $action = Action::make($name)->label($label)->schemaComponent($this);

        if ($name === 'previous') {
            $action->outlined();
        }

        if ($modifyUsing === null) {
            return $action;
        }

        $modified = $this->evaluate($modifyUsing, ['action' => $action], [Action::class => $action]);

        return $modified instanceof Action ? $modified : $action;
    }

    /**
     * The submit action, so pages resolve it by name like section header actions. It is bound
     * to the wizard's field context too, so `$get` / `$set` / `$component` work.
     *
     * @return array<Action>
     */
    public function getAllActions(): array
    {
        $action = $this->getSubmitAction();

        return $action instanceof Action ? [$this->prepareAction($action, withFieldContext: true)] : [];
    }

    /**
     * Inside a form the submit action runs through the field action endpoint
     * (`field:{schema}::{key}`), like section header actions, so `$set` reaches the form.
     *
     * @return array<string, Action>
     */
    public function getFieldActions(): array
    {
        $actions = [];

        foreach ($this->getAllActions() as $action) {
            $actions[$action->getName()] = $action;
        }

        return $actions;
    }

    public function getFieldAction(string $name): ?Action
    {
        return $this->getFieldActions()[$name] ?? null;
    }

    /**
     * @return array<string, mixed>|false|null
     */
    protected function serializeNavigationAction(Action $action, bool $isCustomised): array|false|null
    {
        if (! $isCustomised) {
            return null;
        }

        return $action->isVisible() ? $action->toSchema() : false;
    }

    /**
     * `deferLoading()` on the wizard defers each step's content: the step list is always sent.
     */
    public function shouldRenderDeferredPlaceholder(): bool
    {
        return false;
    }

    protected function getSchemaProps(): array
    {
        $submitAction = $this->getSubmitAction();
        $nextAction = $this->getNextAction();
        $previousAction = $this->getPreviousAction();

        return [
            'startStep' => $this->evaluate($this->startStep),
            'skippable' => $this->evaluate($this->isSkippable) ?: null,
            'submitActionLabel' => $this->evaluate($this->submitActionLabel),
            'submitAction' => $submitAction instanceof Action && $submitAction->isVisible() ? $submitAction->schemaComponent($this)->toSchema() : null,
            'submitActionHtml' => $submitAction instanceof Htmlable ? Html::from($submitAction) : null,
            'nextActionLabel' => $nextAction->getLabel(),
            'previousActionLabel' => $previousAction->getLabel(),
            'nextAction' => $this->serializeNavigationAction($nextAction, $this->modifyNextActionUsing !== null),
            'previousAction' => $this->serializeNavigationAction($previousAction, $this->modifyPreviousActionUsing !== null),
            'completedIcon' => $this->getCompletedIcon(),
            'persistStepInQueryString' => $this->getStepQueryStringKey(),
            // A fresh id per request, so asking for the same step again moves there again.
            'goToStep' => $this->requestedStep !== null ? ['step' => $this->requestedStep, 'id' => (string) Str::ulid()] : null,
        ];
    }
}
