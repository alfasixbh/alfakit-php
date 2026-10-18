<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Standalone;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Support\Enums\Color;
use Illuminate\Database\Eloquent\Model;

/**
 * A form outside a panel. Define the fields in `form()` and handle the validated data in
 * `submit()`; the submit button, validation, live fields, uploads and notifications work
 * as inside a panel. Bind a record with `->record($model)` (or `make(['record' => $model])`)
 * to fill the form from it and validate as an edit.
 *
 * ```php
 * class ContactForm extends \AlfaSix\Alfakit\Standalone\Form
 * {
 *     public function form(Schema $schema): Schema
 *     {
 *         return $schema->components([
 *             TextInput::make('email')->email()->required(),
 *             Textarea::make('message')->required(),
 *         ]);
 *     }
 *
 *     public function submit(array $data): mixed
 *     {
 *         Mail::to('support@example.com')->send(new ContactMessage($data));
 *         Notification::make()->success()->title(__('Thanks, we will reply soon.'))->send();
 *
 *         return back();
 *     }
 * }
 * ```
 */
abstract class Form extends Component
{
    /** Session flash key (suffixed with a hash of the class) of a pending `reset()`. */
    public const RESET_FLASH_KEY = 'alfakit.standalone.reset';

    protected ?string $submitLabel = null;

    /**
     * Empty the form after every successful `submit()` (see `reset()`). Set it in the
     * class or with `->resetAfterSubmit()` when building the payload.
     */
    protected bool $shouldResetAfterSubmit = false;

    /** Whether `reset()` was called during this request. */
    protected bool $hasPendingReset = false;

    /** @var array<string> */
    protected array $submitKeyBindings = [];

    /**
     * Handle the validated form data. Return a response (`back()`, `redirect()`, …) or
     * nothing to redirect back to the page. Return the record you created to have the
     * form's relationship fields saved to it (and redirect back); with a bound record they
     * are saved to that record.
     *
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): mixed
    {
        return null;
    }

    /**
     * Empty the form after every successful submit: the next render fills it with the
     * fields' defaults, ignoring `fill()` data and the bound record. The option travels in
     * the context token, so it holds for the submit request.
     */
    public function resetAfterSubmit(bool $condition = true): static
    {
        $this->shouldResetAfterSubmit = $condition;
        $this->routeParameters['resetAfterSubmit'] = $condition;
        $this->cachedPayload = null;

        return $this;
    }

    public function shouldResetAfterSubmit(): bool
    {
        return $this->shouldResetAfterSubmit;
    }

    /**
     * Call in `submit()` to show an empty form afterwards (Filament's
     * `$this->form->fill()`): when the page renders again the form is filled with the
     * fields' defaults plus `$data`, ignoring `fill()` data and the bound record.
     *
     * @param  array<string, mixed>  $data
     */
    public function reset(array $data = []): static
    {
        $this->hasPendingReset = true;

        if (request()->hasSession()) {
            request()->session()->flash(static::getResetFlashKey(), $data);
        }

        return $this;
    }

    protected function flushCaches(): void
    {
        parent::flushCaches();

        $this->hasPendingReset = false;
    }

    public static function getResetFlashKey(): string
    {
        return self::RESET_FLASH_KEY.'.'.md5(static::class);
    }

    protected function fillFormState(Schema $form): void
    {
        $key = static::getResetFlashKey();

        // The render after a `reset()`: defaults (plus the data passed to it) only.
        if (! $this->hasPendingReset && request()->hasSession() && request()->session()->has($key)) {
            $data = request()->session()->get($key);
            $form->fill(is_array($data) ? $data : []);

            return;
        }

        parent::fillFormState($form);
    }

    /**
     * `resetAfterSubmit` travels in the token like the other parameters, but is an
     * option of the form rather than a `mount()` argument.
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function assignParameters(array $parameters): void
    {
        $reset = $parameters['resetAfterSubmit'] ?? null;
        unset($parameters['resetAfterSubmit']);

        parent::assignParameters($parameters);

        if ($reset !== null) {
            $this->shouldResetAfterSubmit = (bool) $reset;
            $this->routeParameters['resetAfterSubmit'] = (bool) $reset;
        }
    }

    public function submitLabel(?string $label): static
    {
        $this->submitLabel = $label;
        $this->cachedActions = null;
        $this->cachedPayload = null;

        return $this;
    }

    public function getSubmitLabel(): string
    {
        return $this->submitLabel ?? __('alfakit::ui.submit');
    }

    /**
     * Keyboard shortcuts for the submit button (`['mod+s']`).
     *
     * @param  array<string>  $bindings
     */
    public function submitKeyBindings(array $bindings): static
    {
        $this->submitKeyBindings = $bindings;

        return $this;
    }

    /**
     * The submit button. Override to add more buttons (`[...parent::getFormActions(), …]`).
     *
     * @return array<Action|\AlfaSix\Alfakit\Actions\ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [$this->getSubmitAction()];
    }

    protected function getSubmitAction(): Action
    {
        return Action::make('submit')
            ->label($this->getSubmitLabel())
            ->color(Color::Primary)
            ->button()
            ->keyBindings($this->submitKeyBindings)
            ->form(fn (): array => $this->getForm()?->getComponents(withHidden: true) ?? [])
            // The form's `model()` lets relationship fields resolve on create.
            ->model(fn (): ?string => $this->getForm()?->getModel())
            ->action(function (array $data, Action $action): mixed {
                $result = $this->submit($data);

                if ($this->shouldResetAfterSubmit && ! $this->hasPendingReset) {
                    $this->reset();
                }

                // Relationship fields (a multiple `Select`, a relationship `Repeater`…) aren't
                // part of `$data`: save them to the record `submit()` created and returned,
                // or to the bound record.
                $record = $result instanceof Model ? $result : $this->record;

                if ($record !== null && $record->exists) {
                    $action->getForm()?->record($record)->saveRelationships();
                }

                return $result instanceof Model ? null : $result;
            });
    }
}
