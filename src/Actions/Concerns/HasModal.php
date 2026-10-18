<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use AlfaSix\Alfakit\Support\Enums\Alignment;
use AlfaSix\Alfakit\Support\Enums\SlideOverPosition;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\Alfakit\Support\Icons;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Htmlable;

trait HasModal
{
    protected bool|Closure $requiresConfirmation = false;

    protected string|Htmlable|Closure|null $modalHeading = null;

    protected string|Htmlable|Closure|null $modalDescription = null;

    protected string|Closure|null $modalSubmitActionLabel = null;

    protected string|Closure|null $modalCancelActionLabel = null;

    protected string|BackedEnum|\Illuminate\Contracts\Support\Htmlable|Closure|null $modalIcon = null;

    protected string|Closure|null $modalIconColor = null;

    protected Width|string|Closure|null $modalWidth = null;

    protected bool|Closure $isModalSlideOver = false;

    protected Alignment|Closure|null $modalAlignment = null;

    protected bool|Closure $isModalFooterSticky = false;

    protected bool|Closure $isModalHeaderSticky = false;

    protected SlideOverPosition|Closure|null $slideOverPosition = null;

    protected Alignment|Closure|null $modalFooterActionsAlignment = null;

    protected bool|Closure $isModalClickThrough = false;

    /** @var array<array<string, mixed>|Closure> */
    protected array $extraModalWindowAttributes = [];

    /** @var array<array<string, mixed>|Closure> */
    protected array $extraModalOverlayAttributes = [];

    protected bool|Closure $isModalAutofocused = true;

    protected bool|Closure $isModalClosedByClickingAway = true;

    protected bool|Closure $isModalClosedByEscaping = true;

    protected bool|Closure $hasModalCloseButton = true;

    protected bool|Closure $hasModalSubmitAction = true;

    protected bool|Closure $hasModalCancelAction = true;

    protected bool|Closure $isModalHidden = false;

    protected bool|Closure|null $hasUnsavedChangesAlert = null;

    /** @var array<\AlfaSix\Alfakit\Schemas\Components\Component>|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null */
    protected array|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null $modalContent = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $modalContentData = [];

    /** @var array<\AlfaSix\Alfakit\Schemas\Components\Component>|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null */
    protected array|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null $modalContentFooter = null;

    /** @var array<string, mixed>|Closure */
    protected array|Closure $modalContentFooterData = [];

    /** @var array<\AlfaSix\Alfakit\Actions\Action>|Closure|null */
    protected array|Closure|null $extraModalFooterActions = null;

    public function requiresConfirmation(bool|Closure $condition = true): static
    {
        $this->requiresConfirmation = $condition;

        return $this;
    }

    public function modalHeading(string|Htmlable|Closure|null $heading): static
    {
        $this->modalHeading = $heading;

        return $this;
    }

    public function modalDescription(string|Htmlable|Closure|null $description): static
    {
        $this->modalDescription = $description;

        return $this;
    }

    public function modalSubmitActionLabel(string|Closure|null $label): static
    {
        $this->modalSubmitActionLabel = $label;

        return $this;
    }

    public function modalCancelActionLabel(string|Closure|null $label): static
    {
        $this->modalCancelActionLabel = $label;

        return $this;
    }

    public function modalIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->modalIcon = $icon;

        return $this;
    }

    public function modalIconColor(string|Closure|null $color): static
    {
        $this->modalIconColor = $color;

        return $this;
    }

    public function modalWidth(Width|string|Closure|null $width): static
    {
        $this->modalWidth = $width;

        return $this;
    }

    public function slideOver(bool|Closure $condition = true): static
    {
        $this->isModalSlideOver = $condition;

        return $this;
    }

    public function modalAlignment(Alignment|Closure|null $alignment): static
    {
        $this->modalAlignment = $alignment;

        return $this;
    }

    public function stickyModalFooter(bool|Closure $condition = true): static
    {
        $this->isModalFooterSticky = $condition;

        return $this;
    }

    /**
     * Keep the heading visible while the modal content scrolls (slide-overs always do).
     */
    public function stickyModalHeader(bool|Closure $condition = true): static
    {
        $this->isModalHeaderSticky = $condition;

        return $this;
    }

    public function isModalHeaderSticky(): bool
    {
        return (bool) $this->evaluate($this->isModalHeaderSticky);
    }

    public function isModalFooterSticky(): bool
    {
        return (bool) $this->evaluate($this->isModalFooterSticky);
    }

    /**
     * The side a `slideOver()` enters from: `SlideOverPosition::End` (default) or `Start`.
     * Logical sides, so RTL panels mirror them.
     */
    public function slideOverPosition(SlideOverPosition|Closure|null $position): static
    {
        $this->slideOverPosition = $position;

        return $this;
    }

    public function getSlideOverPosition(): SlideOverPosition
    {
        return $this->evaluate($this->slideOverPosition) ?? SlideOverPosition::End;
    }

    /**
     * Align the footer buttons (`Alignment::Start`, `Center`, `End`, `Between`) without
     * moving the heading (`modalAlignment()` moves both).
     */
    public function modalFooterActionsAlignment(Alignment|Closure|null $alignment): static
    {
        $this->modalFooterActionsAlignment = $alignment;

        return $this;
    }

    public function getModalFooterActionsAlignment(): ?Alignment
    {
        return $this->evaluate($this->modalFooterActionsAlignment);
    }

    /**
     * No backdrop: the page under the modal stays scrollable and clickable. The modal then
     * only closes with its close button, cancel button or Escape.
     */
    public function modalClickThrough(bool|Closure $condition = true): static
    {
        $this->isModalClickThrough = $condition;

        return $this;
    }

    public function isModalClickThrough(): bool
    {
        return (bool) $this->evaluate($this->isModalClickThrough);
    }

    /**
     * HTML attributes for the modal window (`class` is merged with the window's classes).
     * Calling it again replaces earlier attributes unless `$merge` is true.
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraModalWindowAttributes(array|Closure $attributes, bool $merge = false): static
    {
        $this->extraModalWindowAttributes = $merge ? [...$this->extraModalWindowAttributes, $attributes] : [$attributes];

        return $this;
    }

    /**
     * HTML attributes for the backdrop behind the modal.
     *
     * @param  array<string, mixed>|Closure  $attributes
     */
    public function extraModalOverlayAttributes(array|Closure $attributes, bool $merge = false): static
    {
        $this->extraModalOverlayAttributes = $merge ? [...$this->extraModalOverlayAttributes, $attributes] : [$attributes];

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraModalWindowAttributes(): array
    {
        return $this->resolveModalAttributes($this->extraModalWindowAttributes);
    }

    /**
     * @return array<string, mixed>
     */
    public function getExtraModalOverlayAttributes(): array
    {
        return $this->resolveModalAttributes($this->extraModalOverlayAttributes);
    }

    /**
     * @param  array<array<string, mixed>|Closure>  $stack
     * @return array<string, mixed>
     */
    protected function resolveModalAttributes(array $stack): array
    {
        $attributes = [];

        foreach ($stack as $item) {
            $resolved = $this->evaluate($item);
            $attributes = [...$attributes, ...(is_array($resolved) ? $resolved : [])];
        }

        return $attributes;
    }

    /**
     * An extra footer button that submits this modal's form, running this action with
     * `$arguments` (read them as `$arguments` in `action()`):
     *
     * ```php
     * ->extraModalFooterActions(fn (Action $action): array => [
     *     $action->makeModalSubmitAction('saveAndPublish', arguments: ['publish' => true]),
     * ])
     * ```
     *
     * @param  array<string, mixed>  $arguments
     */
    public function makeModalSubmitAction(string $name, array $arguments = []): \AlfaSix\Alfakit\Actions\Action
    {
        return \AlfaSix\Alfakit\Actions\Action::make($name)
            ->arguments($arguments)
            ->submitsModal()
            ->color($this->getColor());
    }

    public function modalAutofocus(bool|Closure $condition = true): static
    {
        $this->isModalAutofocused = $condition;

        return $this;
    }

    public function closeModalByClickingAway(bool|Closure $condition = true): static
    {
        $this->isModalClosedByClickingAway = $condition;

        return $this;
    }

    public function closeModalByEscaping(bool|Closure $condition = true): static
    {
        $this->isModalClosedByEscaping = $condition;

        return $this;
    }

    public function modalCloseButton(bool|Closure $condition = true): static
    {
        $this->hasModalCloseButton = $condition;

        return $this;
    }

    /**
     * `false` removes the submit button; a closure receives the button as an `Action`
     * (`fn (Action $action) => $action->label('Send')->color('danger')`) and may return a
     * changed action, or a boolean to show or hide it.
     */
    public function modalSubmitAction(bool|Closure $condition = true): static
    {
        $this->hasModalSubmitAction = $condition;

        return $this;
    }

    /**
     * `false` removes the cancel button; a closure receives it as an `Action`
     * (`fn (Action $action) => $action->label('Close')`).
     */
    public function modalCancelAction(bool|Closure $condition = true): static
    {
        $this->hasModalCancelAction = $condition;

        return $this;
    }

    /**
     * Skip the modal (confirmation, content and form) and run the action straight away; the
     * form, if any, runs with its default state.
     */
    public function modalHidden(bool|Closure $condition = true): static
    {
        $this->isModalHidden = $condition;

        return $this;
    }

    public function isModalHidden(): bool
    {
        return (bool) $this->evaluate($this->isModalHidden);
    }

    /**
     * Ask before closing the modal while its form has unsaved changes (and before leaving
     * the page). Defaults to the panel's `unsavedChangesAlerts()`.
     */
    public function unsavedChangesAlert(bool|Closure|null $condition = true): static
    {
        $this->hasUnsavedChangesAlert = $condition;

        return $this;
    }

    /**
     * Whether closing the modal with a changed form asks first: never for a disabled
     * (read-only) form or a modal without a form.
     */
    public function hasUnsavedChangesAlert(): bool
    {
        if (! $this->hasForm() || $this->isFormDisabled()) {
            return false;
        }

        $condition = $this->evaluate($this->hasUnsavedChangesAlert);

        if ($condition !== null) {
            return (bool) $condition;
        }

        return alfakit()->getCurrentOrDefaultPanel()->hasUnsavedChangesAlerts();
    }

    /**
     * The modal's submit button as an action (label, colour, icon), or null when removed.
     */
    public function getModalSubmitAction(): ?\AlfaSix\Alfakit\Actions\Action
    {
        $action = \AlfaSix\Alfakit\Actions\Action::make('submit')
            ->label($this->getModalSubmitActionLabel())
            ->color($this->getColor());

        return $this->resolveModalFooterAction($action, $this->hasModalSubmitAction);
    }

    /**
     * The modal's cancel button as an action, or null when removed.
     */
    public function getModalCancelAction(): ?\AlfaSix\Alfakit\Actions\Action
    {
        $action = \AlfaSix\Alfakit\Actions\Action::make('cancel')
            ->label($this->getModalCancelActionLabel());

        return $this->resolveModalFooterAction($action, $this->hasModalCancelAction);
    }

    protected function resolveModalFooterAction(\AlfaSix\Alfakit\Actions\Action $action, bool|Closure $configuration): ?\AlfaSix\Alfakit\Actions\Action
    {
        if ($configuration instanceof Closure) {
            $result = $this->evaluate($configuration, ['action' => $action], [\AlfaSix\Alfakit\Actions\Action::class => $action]);

            if ($result instanceof \AlfaSix\Alfakit\Actions\Action) {
                $action = $result;
            } elseif ($result === false) {
                return null;
            }
        } elseif (! $configuration) {
            return null;
        }

        $action->context($this->getContext());

        return $action->isHidden() ? null : $action;
    }

    /**
     * Custom content rendered above the form. Accepts:
     *
     * - read-only schema components (infolist entries, `Text`, `Html`…), one or an array;
     * - a frontend component registered with `createAlfakit({ components })`, by name, with
     *   `$data` as its props: `->modalContent('order-summary', fn (Order $record): array => [...])`.
     *   Closures in `$data` (or `$data` itself) get the action's `$record`, `$data`,
     *   `$arguments`… injected;
     * - a Blade view or other `Htmlable` (`view('actions.advance', ['record' => $record])`),
     *   rendered to trusted HTML on the server.
     *
     * The content itself may be a closure returning any of these.
     *
     * @param  array<\AlfaSix\Alfakit\Schemas\Components\Component>|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null  $content
     * @param  array<string, mixed>|Closure  $data
     */
    public function modalContent(array|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null $content, array|Closure $data = []): static
    {
        $this->modalContent = $content;
        $this->modalContentData = $data;

        return $this;
    }

    /**
     * Custom content below the form; takes the same values as `modalContent()`.
     *
     * @param  array<\AlfaSix\Alfakit\Schemas\Components\Component>|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null  $content
     * @param  array<string, mixed>|Closure  $data
     */
    public function modalContentFooter(array|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null $content, array|Closure $data = []): static
    {
        $this->modalContentFooter = $content;
        $this->modalContentFooterData = $data;

        return $this;
    }

    /**
     * The `modalContent()` components, or null without content.
     *
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>|null
     */
    public function getModalContentComponents(): ?array
    {
        return $this->resolveModalContentComponents($this->modalContent, $this->modalContentData);
    }

    /**
     * The `modalContentFooter()` components, or null without content.
     *
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>|null
     */
    public function getModalContentFooterComponents(): ?array
    {
        return $this->resolveModalContentComponents($this->modalContentFooter, $this->modalContentFooterData);
    }

    /**
     * @param  array<\AlfaSix\Alfakit\Schemas\Components\Component>|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null  $content
     * @param  array<string, mixed>|Closure  $data
     * @return array<\AlfaSix\Alfakit\Schemas\Components\Component>|null
     */
    protected function resolveModalContentComponents(array|\AlfaSix\Alfakit\Schemas\Components\Component|Htmlable|string|Closure|null $content, array|Closure $data): ?array
    {
        if ($content === null) {
            return null;
        }

        $content = $this->evaluate($content);

        return match (true) {
            $content === null => [],
            is_array($content) => array_values($content),
            $content instanceof \AlfaSix\Alfakit\Schemas\Components\Component => [$content],
            // Blade views and other Htmlables are markup written in code: rendered as-is.
            $content instanceof Htmlable => [\AlfaSix\Alfakit\Schemas\Components\Html::make($content)],
            is_string($content) => [\AlfaSix\Alfakit\Schemas\Components\View::make($content)->props($this->resolveModalContentData($data))],
            default => [],
        };
    }

    /**
     * Props of a frontend `modalContent()` component: the data, with closures (the array's
     * own or its values) evaluated with the action's injections.
     *
     * @param  array<string, mixed>|Closure  $data
     * @return array<string, mixed>
     */
    protected function resolveModalContentData(array|Closure $data): array
    {
        $data = $this->evaluate($data);

        if (! is_array($data)) {
            return [];
        }

        $props = [];

        foreach ($data as $key => $value) {
            $props[(string) $key] = $value instanceof Closure ? $this->evaluate($value) : $value;
        }

        return $props;
    }

    /**
     * @param  array<\AlfaSix\Alfakit\Actions\Action|\AlfaSix\Alfakit\Actions\ActionGroup>|Closure|null  $actions
     */
    public function extraModalFooterActions(array|Closure|null $actions): static
    {
        $this->extraModalFooterActions = $actions;

        return $this;
    }

    public function requiresConfirmationResolved(): bool
    {
        return (bool) $this->evaluate($this->requiresConfirmation);
    }

    public function hasModal(): bool
    {
        // The password is typed in the modal, so it can't be skipped.
        if ($this->isModalHidden()) {
            return method_exists($this, 'isPasswordConfirmationRequired') && $this->isPasswordConfirmationRequired();
        }

        return $this->requiresConfirmationResolved()
            || $this->hasForm()
            || $this->modalContent !== null
            || $this->modalContentFooter !== null
            // The password is typed in the modal.
            || (method_exists($this, 'hasPasswordConfirmation') && $this->hasPasswordConfirmation());
    }

    public function getModalHeading(): ?string
    {
        $heading = $this->evaluate($this->modalHeading) ?? $this->getLabel();

        return $heading instanceof Htmlable ? $heading->toHtml() : $heading;
    }

    /**
     * The modal icon, with registered aliases (`Icons::register()`, `alfakit::*`) resolved.
     */
    public function getModalIcon(): ?string
    {
        return Icons::resolve($this->evaluate($this->modalIcon));
    }

    public function getModalDescription(): ?string
    {
        $description = $this->evaluate($this->modalDescription);

        if ($description === null && $this->requiresConfirmationResolved() && ! $this->hasForm()) {
            $description = __('alfakit::actions.modal.confirmation');
        }

        return \AlfaSix\Alfakit\Support\Html::from($description);
    }

    public function getModalSubmitActionLabel(): string
    {
        return $this->evaluate($this->modalSubmitActionLabel)
            ?? ($this->requiresConfirmationResolved() && ! $this->hasForm() ? __('alfakit::actions.modal.confirm') : __('alfakit::actions.modal.submit'));
    }

    public function getModalCancelActionLabel(): string
    {
        return $this->evaluate($this->modalCancelActionLabel) ?? __('alfakit::actions.modal.cancel');
    }

    public function getModalWidth(): string
    {
        $width = $this->evaluate($this->modalWidth);

        if ($width instanceof Width) {
            return $width->value;
        }

        if ($width !== null) {
            return $width;
        }

        if ($this->evaluate($this->isModalSlideOver)) {
            return Width::Medium->value;
        }

        return $this->hasForm() ? Width::TwoExtraLarge->value : Width::Small->value;
    }
}
