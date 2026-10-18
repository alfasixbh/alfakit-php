<?php

declare(strict_types=1);

namespace AlfaSix\Alfakit\Actions;

use AlfaSix\Alfakit\Auth\Impersonation\Impersonation;
use AlfaSix\Alfakit\Support\Enums\Color;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Sign in as the row's user (for a users table). Shown only when
 * `Impersonation::canImpersonate()` allows it; a banner lets the real user leave.
 *
 * ```php
 * $table->recordActions([ImpersonateAction::make()]);
 * ```
 */
class ImpersonateAction extends Action
{
    protected string|Closure|null $redirectTo = null;

    protected string|Closure|null $backTo = null;

    public static function make(string $name = 'impersonate'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('alfakit::impersonation.action'));
        $this->icon('venetian-mask');
        $this->color(Color::Gray);
        $this->requiresConfirmation();
        $this->modalHeading(fn (): string => __('alfakit::impersonation.modal_heading', ['name' => $this->getRecordTitle() ?? '']));
        $this->modalDescription(__('alfakit::impersonation.modal_description'));
        $this->modalSubmitActionLabel(__('alfakit::impersonation.action'));

        $this->authorize(fn (?Model $record): bool => $this->canImpersonateRecord($record));

        $this->action(function (?Model $record) {
            abort_unless($record instanceof Authenticatable && $this->canImpersonateRecord($record), 403);

            $panel = alfakit()->getCurrentOrDefaultPanel();

            return Impersonation::start(
                $panel,
                $record,
                backUrl: $this->evaluate($this->backTo) ?? url()->previous($panel->getUrl()),
                redirectUrl: $this->evaluate($this->redirectTo, ['record' => $record]),
            );
        });
    }

    /**
     * Where to go after switching user (defaults to the panel home).
     */
    public function redirectTo(string|Closure|null $url): static
    {
        $this->redirectTo = $url;

        return $this;
    }

    /**
     * Where leaving the impersonation returns to (defaults to the page the action ran on).
     */
    public function backTo(string|Closure|null $url): static
    {
        $this->backTo = $url;

        return $this;
    }

    public function isHidden(): bool
    {
        $record = $this->getRecord();

        return parent::isHidden() || ($record !== null && ! $this->canImpersonateRecord($record));
    }

    protected function canImpersonateRecord(?Model $record): bool
    {
        $panel = alfakit()->getCurrentOrDefaultPanel();

        return Impersonation::canImpersonate($panel, $panel->auth()->user(), $record);
    }
}
