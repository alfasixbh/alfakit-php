<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions\Concerns;

use Closure;

/**
 * `formId('form')`: the button submits a form of the page, wherever it is rendered. The
 * renderer sends that form's current state as the action's data (and shows its
 * validation errors and processing state), like the form's own buttons do:
 *
 * ```php
 * protected function getHeaderActions(): array
 * {
 *     return [$this->getSaveFormAction()->formId('form')];
 * }
 * ```
 *
 * `form` is the page form; named forms of custom pages are `form:{name}`.
 */
trait CanSubmitForm
{
    protected string|Closure|null $formId = null;

    public function formId(string|Closure|null $id): static
    {
        $this->formId = $id;

        return $this;
    }

    public function getFormId(): ?string
    {
        $id = $this->evaluate($this->formId);

        return is_string($id) && $id !== '' ? $id : null;
    }
}
