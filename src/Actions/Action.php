<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Actions\Concerns\CanAccessSelectedRecords;
use AlfaSix\Alfakit\Actions\Concerns\CanBeAuthorized;
use AlfaSix\Alfakit\Actions\Concerns\CanBeRateLimited;
use AlfaSix\Alfakit\Actions\Concerns\CanBeUndone;
use AlfaSix\Alfakit\Actions\Concerns\CanOpenUrl;
use AlfaSix\Alfakit\Actions\Concerns\CanRequirePasswordConfirmation;
use AlfaSix\Alfakit\Actions\Concerns\CanSubmitForm;
use AlfaSix\Alfakit\Actions\Concerns\CanUseDatabaseTransactions;
use AlfaSix\Alfakit\Actions\Concerns\HasForm;
use AlfaSix\Alfakit\Actions\Concerns\HasLifecycleHooks;
use AlfaSix\Alfakit\Actions\Concerns\HasModal;
use AlfaSix\Alfakit\Actions\Concerns\HasModalActions;
use AlfaSix\Alfakit\Actions\Concerns\HasNotifications;
use AlfaSix\Alfakit\Actions\Concerns\HasWizard;
use AlfaSix\Alfakit\Actions\Concerns\InteractsWithFieldContext;
use AlfaSix\Alfakit\Actions\Concerns\InteractsWithPivot;
use AlfaSix\Alfakit\Actions\Concerns\InteractsWithRecord;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasColor;
use AlfaSix\Alfakit\Schemas\Components\Concerns\HasIcon;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Get;
use AlfaSix\Alfakit\Schemas\Components\Utilities\Set;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Concerns\Configurable;
use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\Alfakit\Support\Concerns\HasExtraAttributes;
use AlfaSix\Alfakit\Support\Enums\ActionVariant;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\Labels;
use AlfaSix\Alfakit\Support\Serializable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;

/**
 * A button that links somewhere, opens a modal (confirmation and/or form) and/or runs
 * server-side logic. Actions are serialised for the renderer and executed through the
 * panel's action endpoint.
 */
class Action
{
    use CanAccessSelectedRecords;
    use CanBeAuthorized;
    use CanBeRateLimited;
    use CanBeUndone;
    use CanOpenUrl;
    use CanRequirePasswordConfirmation;
    use CanSubmitForm;
    use CanUseDatabaseTransactions;
    use Configurable;
    use EvaluatesClosures;
    use HasColor;
    use HasExtraAttributes;
    use HasForm;
    use HasIcon;
    use HasLifecycleHooks;
    use HasModal;
    use HasModalActions;
    use HasNotifications;
    use HasWizard;
    use InteractsWithFieldContext;
    use InteractsWithPivot;
    use InteractsWithRecord;
    use Macroable;

    protected string $name;

    protected string|Closure|null $label = null;

    protected bool|Closure $isLabelHidden = false;

    protected ActionVariant|Closure|null $variant = null;

    /** `badge()` without an argument: the trigger is rendered as a badge. */
    protected bool $isBadgeTrigger = false;

    protected Size|Closure|null $size = null;

    protected string|int|Closure|null $badge = null;

    protected string|Closure|null $badgeColor = null;

    protected string|Closure|null $tooltip = null;

    protected bool|Closure $isHidden = false;

    protected bool|Closure $isVisible = true;

    protected bool|Closure $isDisabled = false;

    protected ?ActionGroup $parentGroup = null;

    protected ?Closure $action = null;

    /** @var array<string, mixed> */
    protected array $arguments = [];

    /** @var array<string>|Closure */
    protected array|Closure $keyBindings = [];

    protected string|Closure|null $successRedirectUrl = null;

    protected bool|Closure $shouldCloseModal = true;

    protected ?Component $schemaComponent = null;

    protected mixed $context = null;

    protected ?Closure $recordTitleUsing = null;

    protected bool|Closure $isOutlined = false;

    protected int|Closure|null $sort = null;

    protected string|Closure|null $labeledFrom = null;

    protected bool $isModalSubmit = false;

    protected bool|Closure $opensDatabaseNotifications = false;

    final public function __construct(string $name)
    {
        $this->name = $name;
    }

    public static function make(string $name): static
    {
        $static = new static($name);
        $static->configure();

        return $static;
    }

    protected function getEvaluationIdentifier(): string
    {
        return 'action';
    }

    // ---------------------------------------------------------------- Appearance

    public function getName(): string
    {
        return $this->name;
    }

    public function label(string|Closure|null $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function hiddenLabel(bool|Closure $condition = true): static
    {
        $this->isLabelHidden = $condition;

        return $this;
    }

    /**
     * Show only the icon below a breakpoint (`sm`, `md`, `lg`, `xl`, `2xl`) and the label
     * from it up. The label stays the button's accessible name.
     */
    public function labeledFrom(string|Closure|null $breakpoint): static
    {
        $this->labeledFrom = $breakpoint;

        return $this;
    }

    public function getLabeledFrom(): ?string
    {
        $breakpoint = $this->evaluate($this->labeledFrom);

        return in_array($breakpoint, ['sm', 'md', 'lg', 'xl', '2xl'], true) ? $breakpoint : null;
    }

    /**
     * Make this button submit the modal it sits in (see `makeModalSubmitAction()`): the
     * parent action runs with this button's `arguments()`.
     */
    public function submitsModal(bool $condition = true): static
    {
        $this->isModalSubmit = $condition;

        return $this;
    }

    public function isModalSubmit(): bool
    {
        return $this->isModalSubmit;
    }

    /**
     * Clicking opens the database notifications panel instead of running anything.
     */
    public function openDatabaseNotifications(bool|Closure $condition = true): static
    {
        $this->opensDatabaseNotifications = $condition;

        return $this;
    }

    public function opensDatabaseNotifications(): bool
    {
        return (bool) $this->evaluate($this->opensDatabaseNotifications);
    }

    public function getLabel(): string
    {
        return $this->evaluate($this->label) ?? Labels::fromName($this->name);
    }

    public function variant(ActionVariant|Closure|null $variant): static
    {
        $this->variant = $variant;
        // Another trigger style replaces the badge style (`badge()` without an argument).
        $this->isBadgeTrigger = false;

        return $this;
    }

    public function button(): static
    {
        return $this->variant(ActionVariant::Button);
    }

    public function outlined(bool|Closure $condition = true): static
    {
        $this->isOutlined = $condition;

        return $this;
    }

    public function link(): static
    {
        return $this->variant(ActionVariant::Link);
    }

    public function ghost(): static
    {
        return $this->variant(ActionVariant::Ghost);
    }

    public function iconButton(): static
    {
        return $this->variant(ActionVariant::IconButton);
    }

    public function size(Size|Closure|null $size): static
    {
        $this->size = $size;

        return $this;
    }

    /**
     * `badge()` without an argument shows the trigger as a badge (a tinted pill in the
     * action's colour, with its label and icon); `badge(5)` adds a count badge to the button.
     */
    public function badge(string|int|Closure|null $badge = null): static
    {
        if (func_num_args() === 0) {
            $this->isBadgeTrigger = true;

            return $this;
        }

        $this->badge = $badge;

        return $this;
    }

    public function isBadgeTrigger(): bool
    {
        return $this->isBadgeTrigger;
    }

    public function badgeColor(string|Closure|null $color): static
    {
        $this->badgeColor = $color;

        return $this;
    }

    public function tooltip(string|Closure|null $tooltip): static
    {
        $this->tooltip = $tooltip;

        return $this;
    }

    /**
     * @param  array<string>|Closure  $bindings  e.g. `['mod+s']`
     */
    public function keyBindings(array|Closure $bindings): static
    {
        $this->keyBindings = $bindings;

        return $this;
    }

    /**
     * Order within a user or tenant menu group (lower first).
     */
    public function sort(int|Closure|null $sort): static
    {
        $this->sort = $sort;

        return $this;
    }

    public function getSort(): int
    {
        return (int) ($this->evaluate($this->sort) ?? 0);
    }

    // ---------------------------------------------------------------- Visibility

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

    public function disabled(bool|Closure $condition = true): static
    {
        $this->isDisabled = $condition;

        return $this;
    }

    public function parentGroup(?ActionGroup $group): static
    {
        $this->parentGroup = $group;

        return $this;
    }

    public function isHidden(): bool
    {
        if ($this->evaluate($this->isHidden) || ! $this->evaluate($this->isVisible)) {
            return true;
        }

        if ($this->parentGroup?->isHiddenFor($this->getRecord())) {
            return true;
        }

        // An action inside a modal is only usable while its parent is.
        if ($this->parentAction !== null && ($this->parentAction->isHidden() || ! $this->parentAction->isAuthorized())) {
            return true;
        }

        // `authorizationTooltip()` / `authorizationNotification()` keep an unauthorised
        // action visible when there is a message to show.
        return ! $this->isAuthorized() && ! $this->isUnauthorizedButVisible();
    }

    public function isVisible(): bool
    {
        return ! $this->isHidden();
    }

    /**
     * Disabled by `disabled()`, or unauthorised (an unauthorised action is only ever shown
     * with `authorizationTooltip()` / `authorizationNotification()`, and never runs).
     */
    public function isDisabled(): bool
    {
        return (bool) $this->evaluate($this->isDisabled) || ! $this->isAuthorized();
    }

    // ---------------------------------------------------------------- Behaviour

    public function action(?Closure $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function hasAction(): bool
    {
        return $this->action !== null;
    }

    /**
     * Whether the action navigates instead of running: it has a URL for the current record.
     */
    public function isLink(): bool
    {
        return $this->getUrl() !== null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function arguments(array $arguments): static
    {
        $this->arguments = $arguments;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function successRedirectUrl(string|Closure|null $url): static
    {
        $this->successRedirectUrl = $url;

        return $this;
    }

    public function getSuccessRedirectUrl(): ?string
    {
        return $this->evaluate($this->successRedirectUrl);
    }

    public function closeModal(bool|Closure $condition = true): static
    {
        $this->shouldCloseModal = $condition;

        return $this;
    }

    public function shouldCloseModal(): bool
    {
        return (bool) $this->evaluate($this->shouldCloseModal);
    }

    public function schemaComponent(?Component $component): static
    {
        $this->schemaComponent = $component;

        return $this;
    }

    public function getSchemaComponent(): ?Component
    {
        return $this->schemaComponent;
    }

    /**
     * The object that owns this action (a Page, Table, RelationManager…). Injected into
     * closures by type.
     */
    public function context(mixed $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getContext(): mixed
    {
        return $this->context;
    }

    public function recordTitle(string|Closure|null $title): static
    {
        $this->recordTitleUsing = $title instanceof Closure ? $title : ($title === null ? null : fn (): string => $title);

        return $this;
    }

    protected function getRecordTitleUsing(): ?string
    {
        if ($this->recordTitleUsing === null) {
            return null;
        }

        $title = $this->evaluate($this->recordTitleUsing, ['record' => $this->getRecord()]);

        return $title === null ? null : (string) $title;
    }

    protected function flushForm(): void
    {
        $this->form = null;
    }

    /**
     * Run the action: authorise, validate the modal form (if any, between the
     * `beforeFormValidated()` / `afterFormValidated()` hooks), call the handler and the
     * lifecycle hooks. Returns the handler's return value.
     *
     * @param  array<string, mixed>  $data  Raw form state from the renderer
     * @param  array<string, mixed>  $arguments
     *
     * @throws \Illuminate\Validation\ValidationException
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function execute(array $data = [], array $arguments = []): mixed
    {
        $this->arguments([...$this->arguments, ...$arguments]);

        if (! $this->isAuthorized()) {
            // `authorizationNotification()`: tell the user why instead of failing silently.
            if ($this->sendsAuthorizationNotification()) {
                \AlfaSix\Alfakit\Notifications\Notification::make()
                    ->danger()
                    ->title((string) $this->getAuthorizationMessage())
                    ->send();

                throw new Exceptions\Halt;
            }

            abort(403);
        }

        if ($this->isHidden() || $this->isDisabled()) {
            abort(403);
        }

        $this->ensurePasswordIsConfirmed();
        $this->ensureActionIsNotRateLimited();

        // Without a modal (`modalHidden()`) nothing was mounted in the browser: mount now,
        // and the form runs with its filled state instead of client state.
        $hasModal = $this->hasModal();
        $form = $hasModal ? $this->getForm() : $this->mount();
        $validated = [];

        if ($form !== null) {
            if ($hasModal) {
                $form->applyClientState($data);
            }

            $hookInjections = ['schema' => $form, 'form' => $form, 'record' => $this->getRecord(), 'arguments' => $this->arguments];

            $this->callFormHook('beforeFormValidated', [...$hookInjections, 'data' => $form->getRawState()], [Schema::class => $form]);

            $validated = $form->validate();

            $this->callFormHook('afterFormValidated', [...$hookInjections, 'data' => $validated], [Schema::class => $form]);

            $validated = $this->mutateFormData($validated);
        }

        $this->hitRateLimit();

        $injections = [
            'data' => $validated,
            'arguments' => $this->arguments,
            'record' => $this->getRecord(),
            'form' => $form,
            'schema' => $form,
        ];

        // `undoable()`: what undoing needs is read before the handler changes it.
        $this->prepareUndo();
        $notificationsBefore = \AlfaSix\Alfakit\Notifications\Notification::countSent();

        // Hooks and handler share one transaction when the panel (or action) asks for it,
        // so an exception or `halt()` rolls back everything they wrote.
        $result = $this->runInDatabaseTransaction(function () use ($injections, $form): mixed {
            $this->callBefore($injections);

            $result = $this->action === null ? null : $this->evaluate($this->action, $injections, $form !== null ? [Schema::class => $form] : []);

            $this->callAfter([...$injections, 'result' => $result]);

            return $result;
        });

        // After the commit: the toast's "Undo" button.
        $this->issueUndo($notificationsBefore);

        return $result;
    }

    // ---------------------------------------------------------------- Serialisation

    /**
     * The button descriptor sent with page/table payloads.
     *
     * @return array<string, mixed>
     */
    public function toSchema(): array
    {
        $variant = $this->evaluate($this->variant);
        $size = $this->evaluate($this->size);

        // Unauthorised but shown: a tooltip explains why it's disabled, or clicking runs it
        // on the server, which answers with the message as a notification.
        $isUnauthorized = $this->isUnauthorizedButVisible();
        $sendsNotification = $isUnauthorized && $this->sendsAuthorizationNotification();
        // The URL closure (often a route URL per record) runs once: `isLink()` is `getUrl() !== null`.
        $url = $isUnauthorized ? null : $this->getUrl();
        $isLink = $url !== null;
        $postsToUrl = $isLink && $this->shouldPostToUrl();
        // Client-side buttons: submitting the surrounding modal, opening the notifications.
        $isClientSide = ! $isUnauthorized && ($this->isModalSubmit || $this->opensDatabaseNotifications());

        return Serializable::compact([
            'type' => 'Action',
            'name' => $this->getName(),
            'label' => $this->evaluate($this->isLabelHidden) ? null : $this->getLabel(),
            'icon' => $icon = $this->getIcon(),
            'iconPosition' => $icon ? $this->getIconPosition()->value : null,
            'color' => $this->getColor(),
            'variant' => $this->isBadgeTrigger ? 'badge' : $variant?->value,
            'outlined' => $this->evaluate($this->isOutlined) ?: null,
            'size' => $size?->value,
            'badge' => $badge = $this->evaluate($this->badge),
            // Colour closures receive the badge value as `$badge`.
            'badgeColor' => $this->evaluate($this->badgeColor, ['badge' => $badge]),
            'tooltip' => $isUnauthorized && ! $sendsNotification ? $this->getAuthorizationMessage() : $this->evaluate($this->tooltip),
            'disabled' => ($sendsNotification ? (bool) $this->evaluate($this->isDisabled) : $this->isDisabled()) ?: null,
            'url' => $url,
            'openUrlInNewTab' => $isUnauthorized ? null : ($this->shouldOpenUrlInNewTab() ?: null),
            'postToUrl' => $postsToUrl ?: null,
            // A resolved URL makes the action a link: it navigates and is never executed
            // (e.g. CreateAction/EditAction pointing at the resource's own pages).
            'hasModal' => $isLink || $sendsNotification || $isClientSide ? null : ($this->hasModal() ?: null),
            // A POST link isn't a plain link: the runner sends the request.
            'hasAction' => $sendsNotification || $postsToUrl ? true : ($isLink || $isClientSide ? null : (($this->hasAction() || $this->hasForm()) ?: null)),
            'labeledFrom' => $this->evaluate($this->isLabelHidden) ? null : $this->getLabeledFrom(),
            'submitsModal' => $this->isModalSubmit && ! $isUnauthorized ? true : null,
            'arguments' => $this->isModalSubmit ? ($this->arguments ?: null) : null,
            'opensDatabaseNotifications' => $isClientSide && ! $this->isModalSubmit ? true : null,
            'keyBindings' => $this->evaluate($this->keyBindings) ?: null,
            'record' => $this->getRecordKey(),
            // `formId()`: the button submits that page form with its current state.
            'formId' => $this->getFormId(),
            'extraAttributes' => $this->getExtraAttributes() ?: null,
            'accessSelectedRecords' => $this->canAccessSelectedRecords() ?: null,
        ]);
    }

    /**
     * Everything the renderer needs to open this action's modal.
     *
     * @return array<string, mixed>
     */
    public function toModalSchema(): array
    {
        $form = $this->mount();

        // Components that act when the modal opens (e.g. a second-factor challenge
        // emailing its code).
        foreach ($form?->getFlatComponents(withHidden: true) ?? [] as $component) {
            if ($component instanceof Contracts\MountsWithAction) {
                $component->mountedWithAction($this);
            }
        }

        $submitAction = $this->getModalSubmitAction();
        $cancelAction = $this->getModalCancelAction();
        $slideOver = (bool) $this->evaluate($this->isModalSlideOver);
        $clickThrough = $this->isModalClickThrough();
        $cancelOnClose = $this->getParentActionsToCancelOnClose();

        $serializeComponents = fn (?array $components): ?array => $components === null
            ? null
            : Schema::make()->components($components)->record($this->getRecordModel())->toSchema();

        return Serializable::compact([
            'name' => $this->getName(),
            'heading' => $this->getModalHeading(),
            'description' => $this->getModalDescription(),
            'icon' => $this->getModalIcon() ?? ($this->requiresConfirmationResolved() && ! $this->hasForm() ? $this->getIcon() : null),
            'iconColor' => $this->evaluate($this->modalIconColor) ?? $this->getColor(),
            'width' => $this->getModalWidth(),
            'slideOver' => $slideOver ?: null,
            'slideOverPosition' => $slideOver && $this->getSlideOverPosition() === \AlfaSix\Alfakit\Support\Enums\SlideOverPosition::Start ? 'start' : null,
            'alignment' => $this->evaluate($this->modalAlignment)?->value,
            'footerActionsAlignment' => $this->getModalFooterActionsAlignment()?->value,
            'stickyHeader' => $this->isModalHeaderSticky() ?: null,
            'stickyFooter' => $this->isModalFooterSticky() ?: null,
            'clickThrough' => $clickThrough ?: null,
            'windowAttributes' => $this->getExtraModalWindowAttributes() ?: null,
            'overlayAttributes' => $this->getExtraModalOverlayAttributes() ?: null,
            'autofocus' => (bool) $this->evaluate($this->isModalAutofocused),
            // Without a backdrop there is nothing to click away on.
            'closeByClickingAway' => ! $clickThrough && (bool) $this->evaluate($this->isModalClosedByClickingAway),
            'closeByEscaping' => (bool) $this->evaluate($this->isModalClosedByEscaping),
            'closeButton' => (bool) $this->evaluate($this->hasModalCloseButton),
            'submitLabel' => $submitAction?->getLabel(),
            'cancelLabel' => $cancelAction?->getLabel(),
            'submitColor' => $submitAction?->getColor() ?? $this->getColor(),
            'submitIcon' => $submitAction?->getIcon(),
            'cancelColor' => $cancelAction?->getColor(),
            'unsavedChangesAlert' => $this->hasUnsavedChangesAlert() ?: null,
            'requiresConfirmation' => $this->requiresConfirmationResolved() ?: null,
            'wizard' => $this->hasWizard() ?: null,
            'passwordConfirmation' => $this->isPasswordConfirmationRequired() ?: null,
            'content' => $serializeComponents($this->getModalContentComponents()),
            'contentFooter' => $serializeComponents($this->getModalContentFooterComponents()),
            'form' => $form?->toPayload(),
            'extraFooterActions' => collect($this->getExtraModalFooterActions())
                ->filter(static fn (Action|ActionGroup $action): bool => $action->isVisible())
                ->map(static fn (Action|ActionGroup $action): array => $action->toSchema())
                ->values()
                ->all() ?: null,
            // Nested actions: what closing this modal does to its parents.
            'cancelParentActionsOnClose' => $cancelOnClose,
            // Parents are hidden while a nested modal is open unless it overlays them.
            'overlayParent' => $this->getParentAction() !== null && $this->shouldOverlayParentActions() ? true : null,
            'record' => $this->getRecordKey(),
        ]);
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByName(string $parameterName): array
    {
        if (($fieldContext = $this->resolveFieldContextDependencyByName($parameterName)) !== null) {
            return $fieldContext;
        }

        $form = $this->form;

        return match ($parameterName) {
            'record' => [$this->getRecord()],
            'model' => [$this->getModel()],
            'arguments' => [$this->arguments],
            'data' => [$form?->getRawState() ?? []],
            'form', 'schema' => [$form],
            'get' => [$form !== null && ($first = $form->getComponents(true)[0] ?? null) ? new Get($first) : null],
            'set' => [$form !== null && ($first = $form->getComponents(true)[0] ?? null) ? new Set($first) : null],
            'context' => [$this->context],
            'selectedRecords' => [$this->getSelectedRecords()],
            'mountedActions' => [$this->getMountedActions()],
            'parentAction' => [$this->getParentAction()],
            default => [],
        };
    }

    /**
     * @return array<mixed>
     */
    protected function resolveDefaultClosureDependencyForEvaluationByType(string $parameterType): array
    {
        if (($fieldContext = $this->resolveFieldContextDependencyByType($parameterType)) !== null) {
            return $fieldContext;
        }

        $record = $this->getRecord();

        return match (true) {
            // Array rows of custom `records()` are not models: `Model $record` gets null.
            $parameterType === Model::class => [$record instanceof Model ? $record : null],
            $record instanceof $parameterType => [$record],
            $parameterType === Schema::class => [$this->form],
            $this->context instanceof $parameterType => [$this->context],
            default => [],
        };
    }
}
